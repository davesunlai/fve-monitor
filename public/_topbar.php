<?php
/**
 * Společný topbar + hamburger menu pro všechny stránky (web i /admin/).
 *
 * Parametry (před require):
 *   $pageHeading   - nadpis v topbar (např. "📊 Plnění FVE"). Default = config app name
 *   $activePage    - identifikátor stránky pro zvýraznění aktivní položky:
 *                    'dashboard' | 'comparison' | 'performance' | 'spot' | 'spot_calc'
 *                    | 'admin' | 'admin_alert_settings' | 'admin_alerts_history'
 *                    | 'admin_plants_ote' | 'admin_ote_report' | 'admin_import_csv'
 *                    | 'admin_login_log'
 *                    | 'admin_import_isolar' | 'admin_plant_edit' | 'profile' | null
 *   $showLiveStats - bool (default false). True jen pro dashboard - "last update" + alerts badge
 */
$config        = require __DIR__ . '/../config/config.php';
$pageHeading   = $pageHeading ?? '☀️ ' . $config['app']['name'];
$activePage    = $activePage ?? null;
$showLiveStats = $showLiveStats ?? false;
\FveMonitor\Lib\Auth::start();
$currentUser   = \FveMonitor\Lib\Auth::currentUser();
$_mi = function (string $key) use ($activePage): string {
    return $activePage === $key ? 'menu-item-active' : '';
};
?>
<header class="topbar">
    <h1><a href="/index.php" style="color:inherit;text-decoration:none"><?= htmlspecialchars($pageHeading) ?></a></h1>
    <div class="topbar-meta">
        <?php if ($showLiveStats): ?>
            <span id="last-update">—</span>
            <span id="alert-badge" class="alert-badge hidden">0</span>
        <?php endif; ?>
        <button id="menu-btn" class="menu-btn" aria-label="Menu" aria-haspopup="true" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
    <nav id="main-menu" class="main-menu hidden" aria-hidden="true">
        <?php if ($currentUser): ?>
            <div class="menu-user">
                <div class="menu-user-name"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></div>
                <div class="menu-user-role"><?= htmlspecialchars($currentUser['role']) ?></div>
            </div>
            <div class="menu-sep"></div>
        <?php endif; ?>

        <?php
        // Dynamický render hlavního menu podle ACL — host vidí jen public, user dle permissions
        // (admin_* stránky jsou v separátní admin sekci níže)
        foreach (\FveMonitor\Lib\Acl::pages() as $_p):
            if (str_starts_with($_p['page_key'], 'admin_')) continue;
            if (!\FveMonitor\Lib\Acl::canAccess($_p['page_key'])) continue;
        ?>
            <a href="<?= htmlspecialchars($_p['url']) ?>" class="menu-item <?= $_mi($_p['page_key']) ?>">
                <span class="menu-icon"><?= $_p['icon'] ?: '•' ?></span>
                <span class="menu-label"><?= htmlspecialchars($_p['title']) ?></span>
            </a>
        <?php endforeach; ?>

        <?php if ($currentUser && ($currentUser['role'] ?? '') === 'admin'): ?>
        <a href="https://grafana.sunlai.org/" target="_blank" class="menu-item">
            <span class="menu-icon">📉</span>
            <span class="menu-label">Podrobné grafy</span>
        </a>
        <?php endif; ?>

        <?php if ($showLiveStats): ?>
            <button id="push-toggle" class="menu-item" style="display:none">
                <span class="menu-icon">🔔</span>
                <span class="menu-label">Zapnout notifikace</span>
            </button>
        <?php endif; ?>

        <?php if ($currentUser): ?>
            <?php
            $_hasAdminPages = false;
            foreach (\FveMonitor\Lib\Acl::pages() as $_p2) {
                if (str_starts_with($_p2['page_key'], 'admin_') && \FveMonitor\Lib\Acl::canAccess($_p2['page_key'])) {
                    $_hasAdminPages = true;
                    break;
                }
            }
            if ($_hasAdminPages):
            ?>
            <div class="menu-sep"></div>
            <div class="menu-section-label">Administrace</div>
            <?php endif; ?>

            <?php
            // Dynamický render admin sekce — host přeskakuje, user vidí jen své admin_* permissions
            $_adminPages = array_filter(
                \FveMonitor\Lib\Acl::pages(),
                fn($p) => str_starts_with($p['page_key'], 'admin_')
            );
            $_adminVisible = array_filter(
                $_adminPages,
                fn($p) => \FveMonitor\Lib\Acl::canAccess($p['page_key'])
            );
            foreach ($_adminVisible as $_p):
            ?>
                <a href="<?= htmlspecialchars($_p['url']) ?>" class="menu-item <?= $_mi($_p['page_key']) ?>">
                    <span class="menu-icon"><?= $_p['icon'] ?: '•' ?></span>
                    <span class="menu-label"><?= htmlspecialchars($_p['title']) ?></span>
                </a>
            <?php endforeach; ?>

            <a href="/admin/profile.php" class="menu-item <?= $_mi('profile') ?>">
                <span class="menu-icon">👤</span>
                <span class="menu-label">Můj profil</span>
            </a>

            <div class="menu-sep"></div>
            <a href="/admin/logout.php" class="menu-item menu-item-danger">
                <span class="menu-icon">🚪</span>
                <span class="menu-label">Odhlásit</span>
            </a>
        <?php else: ?>
            <div class="menu-sep"></div>
            <a href="/admin/login.php" class="menu-item">
                <span class="menu-icon">🔐</span>
                <span class="menu-label">Přihlásit se</span>
            </a>
        <?php endif; ?>
    </nav>

    <div id="menu-overlay" class="menu-overlay hidden"></div>
</header>
<script>
// Hamburger menu init - inline aby fungoval i na stránkách bez app.js (admin/*).
(function () {
    if (window.__fveMenuInit) return;
    window.__fveMenuInit = true;
    function init() {
        const btn     = document.getElementById('menu-btn');
        const menu    = document.getElementById('main-menu');
        const overlay = document.getElementById('menu-overlay');
        if (!btn || !menu) return;
        function openMenu() {
            menu.classList.remove('hidden');
            overlay.classList.remove('hidden');
            requestAnimationFrame(() => {
                menu.classList.add('open');
                btn.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
                menu.setAttribute('aria-hidden', 'false');
            });
        }
        function closeMenu() {
            menu.classList.remove('open');
            btn.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
            menu.setAttribute('aria-hidden', 'true');
            setTimeout(() => {
                menu.classList.add('hidden');
                overlay.classList.add('hidden');
            }, 200);
        }
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (menu.classList.contains('open')) closeMenu();
            else openMenu();
        });
        overlay.addEventListener('click', closeMenu);
        menu.querySelectorAll('a.menu-item').forEach(item => {
            item.addEventListener('click', () => setTimeout(closeMenu, 100));
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && menu.classList.contains('open')) closeMenu();
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
