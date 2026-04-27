<?php
/**
 * Dashboard — tabulkový přehled FVE.
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$config = require __DIR__ . '/../config/config.php';

// Zjisti přihlášeného uživatele (nepovinné - dashboard je veřejný)
\FveMonitor\Lib\Auth::start();
$currentUser = \FveMonitor\Lib\Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($config['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#f5b800">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FVE Monitor">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/icon-512.png">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<header class="topbar">
    <h1>☀️ <?= htmlspecialchars($config['app']['name']) ?></h1>
    <div class="topbar-meta">
        <span id="last-update">—</span>
        <span id="alert-badge" class="alert-badge hidden">0</span>

        <!-- Hamburger tlačítko -->
        <button id="menu-btn" class="menu-btn" aria-label="Menu" aria-haspopup="true" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>

    <!-- Dropdown menu -->
    <nav id="main-menu" class="main-menu hidden" aria-hidden="true">
        <?php if ($currentUser): ?>
            <div class="menu-user">
                <div class="menu-user-name"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></div>
                <div class="menu-user-role"><?= htmlspecialchars($currentUser['role']) ?></div>
            </div>
            <div class="menu-sep"></div>
        <?php endif; ?>

        <button id="push-toggle" class="menu-item" style="display:none">
            <span class="menu-icon">🔔</span>
            <span class="menu-label">Zapnout notifikace</span>
        </button>

        <a href="admin/" class="menu-item">
            <span class="menu-icon">⚙</span>
            <span class="menu-label">Admin</span>
        </a>

        <a href="admin/alerts_history.php" class="menu-item">
            <span class="menu-icon">📋</span>
            <span class="menu-label">Historie alertů</span>
        </a>
        <a href="comparison.php" class="menu-item">
            <span class="menu-icon">📊</span>
            <span>Denní srovnání FVE</span>
        </a>

        <?php if ($currentUser): ?>
        <a href="admin/profile.php" class="menu-item">
            <span class="menu-icon">👤</span>
            <span class="menu-label">Můj profil</span>
        </a>
        <?php endif; ?>

        <a href="https://grafana.sunlai.org/" target="_blank" class="menu-item">
            <span class="menu-icon">📊</span>
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

    <!-- Overlay na zavření kliknutím mimo -->
    <div id="menu-overlay" class="menu-overlay hidden"></div>
</header>

<main>
    <!-- Souhrn nahoru -->
    <section id="summary-bar" class="summary-bar">
        <div class="summary-card">
            <div class="summary-label">Aktuální výkon</div>
            <div class="summary-value"><span id="sum-current-kw">—</span><span class="summary-unit">kW</span></div>
            <div class="summary-sub">z <span id="sum-peak-kwp">—</span> kWp instalovaných (<span id="sum-current-pct">—</span> %)</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Dnes vyrobeno</div>
            <div class="summary-value"><span id="sum-today-energy">—</span></div>
            <div class="summary-sub"><span id="sum-plants-count">—</span> elektráren</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Status</div>
            <div class="summary-value">
                <span class="status-pill status-online"><span id="sum-online">—</span> online</span>
                <span class="status-pill status-offline" id="offline-pill"><span id="sum-offline">—</span> offline</span>
            </div>
            <div class="summary-sub">
                <span class="alarm-pill" id="alarm-pill"><span id="sum-alarms">0</span> aktivních alarmů</span>
            </div>
        </div>
    </section>

    <!-- Tabulka FVE -->
    <section id="plants-section">
        <div class="table-wrap">
            <table id="plants-table" class="plants-table">
                <thead>
                    <tr>
                        <th class="col-status"></th>
                        <th class="col-name">Elektrárna</th>
                        <th class="col-num">Aktuálně</th>
                        <th class="col-num">% nominálu</th>
                        <th class="col-num">Dnes</th>
                        <th class="col-num">Měsíc skutečnost</th>
                        <th class="col-num">PVGIS měsíc</th>
                        <th class="col-num">Plnění</th>
                        <th class="col-num">Update</th>
                        <th class="col-sparkline">4denní průběh</th>
                        <th class="col-num">Alarmy</th>
                    </tr>
                </thead>
                <tbody id="plants-tbody">
                    <tr><td colspan="10" class="loading">Načítám…</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Detail vybrané elektrárny -->
    <section id="plant-detail" class="detail hidden">
        <header class="detail-head">
            <h2 id="detail-name">—</h2>
            <button id="detail-close">×</button>
        </header>
        <div class="detail-body">
            <div class="chart-wrap">
                <h3>Dnešní výkon</h3>
                <canvas id="chart-realtime"></canvas>
            </div>
            <div class="chart-wrap">
                <h3>Roční přehled — skutečnost vs PVGIS</h3>
                <div id="yearly-year-tabs" class="year-tabs"></div>
                <canvas id="chart-yearly"></canvas>
                <div id="yearly-table"></div>
            </div>
            <div class="chart-wrap chart-wrap-fullwidth">
                <h3>4denní průběh výkonu</h3>
                <canvas id="chart-48h"></canvas>
            </div>
            <div class="chart-wrap chart-wrap-map">
                <h3>Umístění</h3>
                <div id="detail-map"></div>
            </div>
        </div>
    </section>

    <!-- Alerty -->
    <section id="alerts" class="alerts">
        <h2>⚠ Upozornění</h2>
        <ul id="alerts-list"><li class="empty">Žádná aktivní upozornění.</li></ul>
    </section>
</main>

<footer>
    <small>FVE Monitor · auto-refresh každých 60 s</small>
</footer>

<script src="assets/app.js"></script>
</body>
</html>
