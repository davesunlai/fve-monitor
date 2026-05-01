<?php
/**
 * Společný topbar + hamburger menu pro všechny stránky.
 *
 * Parametry (před require):
 *   $pageHeading   - nadpis v topbar (např. "📊 Plnění FVE"). Default = config app name
 *   $activePage    - 'dashboard' | 'comparison' | 'performance' | 'admin' | 'profile' | null
 *                    pro zvýraznění aktivní položky v menu
 *   $showLiveStats - bool (default false). True jen pro dashboard - ukazuje "last update" + alerts badge
 */
$config        = require __DIR__ . '/../config/config.php';
$pageHeading   = $pageHeading ?? '☀️ ' . $config['app']['name'];
$activePage    = $activePage ?? null;
$showLiveStats = $showLiveStats ?? false;
\FveMonitor\Lib\Auth::start();
$currentUser   = \FveMonitor\Lib\Auth::currentUser();
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

        <a href="index.php" class="menu-item <?= $activePage === 'dashboard' ? 'menu-item-active' : '' ?>">
            <span class="menu-icon">🏠</span>
            <span class="menu-label">Dashboard</span>
        </a>

        <a href="comparison.php" class="menu-item <?= $activePage === 'comparison' ? 'menu-item-active' : '' ?>">
            <span class="menu-icon">📊</span>
            <span class="menu-label">Denní srovnání FVE</span>
        </a>

        <a href="performance.php" class="menu-item <?= $activePage === 'performance' ? 'menu-item-active' : '' ?>">
            <span class="menu-icon">📈</span>
            <span class="menu-label">Plnění FVE (vs PVGIS)</span>
        </a>

        <?php if ($showLiveStats): ?>
            <button id="push-toggle" class="menu-item" style="display:none">
                <span class="menu-icon">🔔</span>
                <span class="menu-label">Zapnout notifikace</span>
            </button>
        <?php endif; ?>

        <div class="menu-sep"></div>

        <a href="admin/" class="menu-item <?= $activePage === 'admin' ? 'menu-item-active' : '' ?>">
            <span class="menu-icon">⚙</span>
            <span class="menu-label">Admin</span>
        </a>

        <a href="admin/alerts_history.php" class="menu-item">
            <span class="menu-icon">📋</span>
            <span class="menu-label">Historie alertů</span>
        </a>

        <?php if ($currentUser): ?>
            <a href="admin/profile.php" class="menu-item <?= $activePage === 'profile' ? 'menu-item-active' : '' ?>">
                <span class="menu-icon">👤</span>
                <span class="menu-label">Můj profil</span>
            </a>
        <?php endif; ?>

        <a href="https://grafana.sunlai.org/" target="_blank" class="menu-item">
            <span class="menu-icon">📉</span>
            <span class="menu-label">Podrobné grafy</span>
        </a>

        <?php if ($currentUser): ?>
            <div class="menu-sep"></div>
            <a href="admin/logout.php" class="menu-item menu-item-danger">
                <span class="menu-icon">🚪</span>
                <span class="menu-label">Odhlásit</span>
            </a>
        <?php else: ?>
            <div class="menu-sep"></div>
            <a href="admin/login.php" class="menu-item">
                <span class="menu-icon">🔐</span>
                <span class="menu-label">Přihlásit se</span>
            </a>
        <?php endif; ?>
    </nav>

    <div id="menu-overlay" class="menu-overlay hidden"></div>
</header>
