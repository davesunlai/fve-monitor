<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * Session-based autentifikace.
 *
 * Poznámky:
 * - Session se startuje přes session_start() s bezpečnými cookie parametry
 * - Login vyžaduje username + heslo → porovná přes password_verify
 * - Po úspěšném loginu uloží user_id do $_SESSION
 * - Kontrola "je přihlášen?" = isset($_SESSION['user_id']) && currentUser() != null
 */
class Auth
{
    private const SESSION_NAME = 'fvemonitor_sid';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,  // 30 dní
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,               // jen přes HTTPS
            'httponly' => true,               // JS nemá přístup
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Znovuotevře session pro zápis (po session_write_close()).
     * Použít v login/logout/passkey flow, kde je potřeba do session zapisovat.
     */
    public static function reopen(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name(self::SESSION_NAME);
        @session_start();
    }

    /**
     * Ověří username/heslo a přihlásí uživatele.
     * @return array|null User row z DB, nebo null pokud selhalo.
     */
    public static function login(string $username, string $password): ?array
    {
        // Neaktivní/neexistující uživatel
        $user = Database::one(
            'SELECT * FROM users WHERE username = ?',
            [$username]
        );
        if (!$user) {
            self::logLoginEvent(null, $username, 'login_fail', 'password', 'unknown_user');
            return null;
        }
        if (!(int)$user['is_active']) {
            self::logLoginEvent((int)$user['id'], $username, 'login_fail', 'password', 'inactive');
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            self::logLoginEvent((int)$user['id'], $username, 'login_fail', 'password', 'bad_password');
            return null;
        }

        self::start();
        session_regenerate_id(true);  // nové session ID (proti session fixation)
        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['login_time'] = time();

        // Aktualizuj last_login_at
        Database::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        // Log success + ulož log_id do session pro spárování s logoutem
        $logId = self::logLoginEvent((int)$user['id'], $username, 'login_success', 'password', null);
        if ($logId) $_SESSION['login_log_id'] = $logId;

        return $user;
    }

    public static function logout(): void
    {
        self::start();

        // Uzavři odpovídající záznam v admin_login_log
        $logId   = $_SESSION['login_log_id'] ?? null;
        $userId  = $_SESSION['user_id'] ?? null;
        $uname   = $_SESSION['username'] ?? '';
        if ($logId) {
            try {
                Database::pdo()
                    ->prepare('UPDATE admin_login_log SET logged_out_at = NOW() WHERE id = ? AND logged_out_at IS NULL')
                    ->execute([(int)$logId]);
            } catch (\Throwable $e) {
                error_log('Auth::logout update failed: ' . $e->getMessage());
            }
        }
        // Samostatný řádek "logout" pro audit
        if ($userId) {
            self::logLoginEvent((int)$userId, (string)$uname, 'logout', 'password', null);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Je někdo přihlášen? */
    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION['user_id']);
    }

    /** Vrátí aktuálního uživatele (z DB, čerstvé data) nebo null. */
    public static function currentUser(): ?array
    {
        self::start();
        if (empty($_SESSION['user_id'])) return null;
        return Database::one(
            'SELECT id, username, email, full_name, role, is_active
             FROM users WHERE id = ? AND is_active = 1',
            [$_SESSION['user_id']]
        );
    }

    /** Ochrana stránky — pokud není přihlášen, redirect na login. */
    public static function requireLogin(string $loginUrl = '/admin/login.php'): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . $loginUrl . '?r=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
        // Ověř že user ještě existuje a je aktivní
        if (self::currentUser() === null) {
            self::logout();
            header('Location: ' . $loginUrl);
            exit;
        }
    }

    /** Ochrana podle role. */
    public static function requireRole(string $role, string $loginUrl = '/admin/login.php'): void
    {
        self::requireLogin($loginUrl);
        $user = self::currentUser();
        if ($user['role'] !== $role && $user['role'] !== 'admin') {
            http_response_code(403);
            die('Nedostatečné oprávnění');
        }
    }

    /**
     * Zápis do admin_login_log. Tichý — selhání nesmí shodit auth flow.
     * @return int|null vložené ID, nebo null při chybě
     */
    public static function logLoginEvent(
        ?int $userId,
        string $username,
        string $event,
        string $method = 'password',
        ?string $failReason = null
    ): ?int {
        try {
            $ip = ActivityTracker::clientIp();
            $ua = ActivityTracker::userAgent();
            $sk = ($event === 'login_success') ? ActivityTracker::sessionKey() : null;

            $pdo = Database::pdo();
            $pdo->prepare('
                INSERT INTO admin_login_log
                    (user_id, username, event, method, fail_reason, ip, user_agent, session_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$userId, $username, $event, $method, $failReason, $ip, $ua, $sk]);

            return (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log('Auth::logLoginEvent failed: ' . $e->getMessage());
            return null;
        }
    }
}
