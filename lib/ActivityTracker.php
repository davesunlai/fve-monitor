<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * Activity tracking — heartbeat pro přihlášené i anonymní návštěvníky.
 *
 * - Pro přihlášené: visitor_key = SHA-256(session_id)
 * - Pro anonymní:   visitor_key = SHA-256(IP + UA)   (GDPR-friendly, žádné nové cookies)
 *
 * Použití (z bootstrap.php nebo z jednotlivých stránek):
 *     ActivityTracker::track();
 *
 * Tracker dělá:
 *   1) UPSERT do activity_sessions (heartbeat, inkrement page_views)
 *   2) INSERT do activity_log (append-only historie)
 *
 * Tracking je tichý — selhání nesmí shodit aplikaci.
 */
class ActivityTracker
{
    /**
     * Hlavní vstupní bod. Zavolat z bootstrap.php nebo na začátku stránky.
     */
    public static function track(): void
    {
        try {
            // Skip pro CLI (crony)
            if (PHP_SAPI === 'cli') return;

            $ip  = self::clientIp();
            $ua  = self::userAgent();
            $uri = self::requestUri();
            $met = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $ref = isset($_SERVER['HTTP_REFERER'])
                ? substr($_SERVER['HTTP_REFERER'], 0, 500)
                : null;

            // Skip některé "šum" requesty (manifesty, service workery, ikony)
            if (self::shouldSkip($uri)) return;

            $userId     = self::currentUserId();
            $visitorKey = self::visitorKey($userId, $ip, $ua);

            $pdo = Database::pdo();

            // UPSERT do activity_sessions
            $pdo->prepare('
                INSERT INTO activity_sessions
                    (visitor_key, user_id, ip, user_agent, last_page, page_views)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    user_id    = VALUES(user_id),
                    ip         = VALUES(ip),
                    user_agent = VALUES(user_agent),
                    last_page  = VALUES(last_page),
                    page_views = page_views + 1,
                    last_seen  = CURRENT_TIMESTAMP
            ')->execute([$visitorKey, $userId, $ip, $ua, substr($uri, 0, 255)]);

            // INSERT do activity_log (append-only)
            $pdo->prepare('
                INSERT INTO activity_log
                    (visitor_key, user_id, ip, user_agent, request_uri, method, referer)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([$visitorKey, $userId, $ip, $ua, substr($uri, 0, 500), $met, $ref]);

        } catch (\Throwable $e) {
            // Tichý fallback — tracking nesmí shodit stránku.
            error_log('ActivityTracker::track failed: ' . $e->getMessage());
        }
    }

    /**
     * Vrátí visitor_key dle pravidel:
     *  - přihlášený: SHA-256(session_id)  (nemíchat s anon, ať se nesloučí)
     *  - anonymní:   SHA-256(ip|ua)
     */
    public static function visitorKey(?int $userId, string $ip, ?string $ua): string
    {
        if ($userId !== null && session_id() !== '') {
            return hash('sha256', 'sess:' . session_id());
        }
        return hash('sha256', 'anon:' . $ip . '|' . ($ua ?? ''));
    }

    /** SHA-256 ze session ID, používá se i v admin_login_log. */
    public static function sessionKey(): ?string
    {
        $sid = session_id();
        return $sid === '' ? null : hash('sha256', 'sess:' . $sid);
    }

    /** Client IP s ohledem na případný reverse proxy (Cloudflare/Nginx). */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    public static function userAgent(): ?string
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
            : null;
    }

    public static function requestUri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    /** Vrátí user_id pokud je někdo přihlášený, jinak null. Nedělá redirecty. */
    private static function currentUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Spustíme session jen pokud už existuje cookie — neotevírat session
            // pro úplně anonymní návštěvníky (zbytečně by vznikaly session soubory).
            $name = 'fvemonitor_sid';
            if (!empty($_COOKIE[$name])) {
                Auth::start();
            }
        }
        $uid = $_SESSION['user_id'] ?? null;
        return $uid ? (int) $uid : null;
    }

    /** Filtr šumových requestů, které netracujeme. */
    private static function shouldSkip(string $uri): bool
    {
        static $patterns = [
            '/sw.js',
            '/manifest.json',
            '/favicon.ico',
            '/robots.txt',
            '/version.json',
            '/assets/',
        ];
        foreach ($patterns as $p) {
            if (str_contains($uri, $p)) return true;
        }
        return false;
    }
}
