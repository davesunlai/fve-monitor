<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * Access Control List — per-stránka oprávnění.
 *
 * Použití na stránce:
 *     Acl::requireAccess('yearly');
 *
 * Podmínky přístupu (sestupně):
 *   1) Stránka je is_public=1 → každý
 *   2) Uživatel je přihlášený a má řádek v user_page_permissions
 *   3) Jinak: redirect na login (anonymous) / 403 (přihlášený bez práv)
 *
 * Admin role nepřebíjí ACL automaticky — admin musí mít taky permission row,
 * jen se mu defaultně zaškrtne vše při vytvoření.
 */
class Acl
{
    /** @var array<string,array>|null lazy cache stránek (page_key => row) */
    private static ?array $pagesCache = null;

    /**
     * Vrátí všechny stránky (cached).
     * @return array<string,array{page_key:string,title:string,url:string,icon:?string,is_public:int,sort_order:int,is_active:int}>
     */
    public static function pages(): array
    {
        if (self::$pagesCache === null) {
            $rows = Database::all(
                'SELECT page_key, title, url, icon, is_public, sort_order, is_active
                   FROM acl_pages
                  WHERE is_active = 1
                  ORDER BY sort_order, page_key'
            );
            self::$pagesCache = [];
            foreach ($rows as $r) {
                self::$pagesCache[$r['page_key']] = $r;
            }
        }
        return self::$pagesCache;
    }

    public static function isPublic(string $pageKey): bool
    {
        $page = self::pages()[$pageKey] ?? null;
        return $page && (int)$page['is_public'] === 1;
    }

    /**
     * Může aktuální (či zadaný) uživatel přistoupit na stránku?
     * $userId === null → použij aktuálně přihlášeného (null = host).
     */
    public static function canAccess(string $pageKey, ?int $userId = null): bool
    {
        // Public stránka = OK pro každého
        if (self::isPublic($pageKey)) return true;

        // Resolve user
        if ($userId === null) {
            $u = Auth::currentUser();
            if (!$u) return false;
            $userId = (int)$u['id'];
        }

        $row = Database::one(
            'SELECT 1 AS ok FROM user_page_permissions WHERE user_id = ? AND page_key = ?',
            [$userId, $pageKey]
        );
        return $row !== null;
    }

    /**
     * Vynutí přístup. Nepřihlášený → redirect na login. Přihlášený bez práv → 403.
     */
    public static function requireAccess(string $pageKey): void
    {
        if (self::canAccess($pageKey)) return;

        if (!Auth::isLoggedIn()) {
            $r = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: /admin/login.php?r=' . urlencode($r));
            exit;
        }

        // Přihlášený, ale nemá práva → tichý redirect na dashboard
        // (lepší UX než 403 obrazovka, dashboard je vždy veřejný)
        header('Location: /?denied=' . urlencode($pageKey));
        exit;
    }

    /**
     * Vrátí page_key pro URL (porovnává s acl_pages.url), nebo null.
     * Bere v úvahu jen path, ne query string.
     */
    public static function pageKeyForUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        foreach (self::pages() as $p) {
            if ($p['url'] === $path) return $p['page_key'];
        }
        // Alias: '/' a '/index.php' míří na dashboard
        if ($path === '/' || $path === '/index.php') {
            return 'dashboard';
        }
        return null;
    }

    /**
     * Může aktuální (či zadaný) uživatel přistoupit na zadané URL?
     * Pokud URL nepatří k žádné ACL stránce, vrátí true (např. /admin/profile.php = vlastní profil).
     */
    public static function canAccessUrl(string $url, ?int $userId = null): bool
    {
        $key = self::pageKeyForUrl($url);
        if ($key === null) return true;
        return self::canAccess($key, $userId);
    }

    /**
     * Permission keys, na které má daný uživatel právo.
     * @return string[]
     */
    public static function userPermissions(int $userId): array
    {
        return array_column(
            Database::all('SELECT page_key FROM user_page_permissions WHERE user_id = ?', [$userId]),
            'page_key'
        );
    }

    /**
     * Nahradí všechny permissions uživatele. Transakčně.
     * @param string[] $pageKeys
     */
    public static function setUserPermissions(int $userId, array $pageKeys): void
    {
        $pdo = Database::pdo();
        // Pokud už je aktivní vnější transakce, nerídíme ji — caller commitne sám.
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_page_permissions WHERE user_id = ?')
                ->execute([$userId]);
            if (!empty($pageKeys)) {
                $ins = $pdo->prepare(
                    'INSERT IGNORE INTO user_page_permissions (user_id, page_key) VALUES (?, ?)'
                );
                // Whitelist proti existujícím page_key
                $valid = array_keys(self::pages());
                foreach ($pageKeys as $pk) {
                    if (in_array($pk, $valid, true)) {
                        $ins->execute([$userId, $pk]);
                    }
                }
            }
            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction) $pdo->rollBack();
            throw $e;
        }
    }
}
