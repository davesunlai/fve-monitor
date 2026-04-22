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
     * Ověří username/heslo a přihlásí uživatele.
     * @return array|null User row z DB, nebo null pokud selhalo.
     */
    public static function login(string $username, string $password): ?array
    {
        $user = Database::one(
            'SELECT * FROM users WHERE username = ? AND is_active = 1',
            [$username]
        );
        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        self::start();
        session_regenerate_id(true);  // nové session ID (proti session fixation)
        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['login_time'] = time();

        // Aktualizuj last_login_at
        Database::pdo()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        return $user;
    }

    public static function logout(): void
    {
        self::start();
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
}
