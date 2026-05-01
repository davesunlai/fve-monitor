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
<?php
$pageTitle = $config['app']['name'];
$includeLeaflet = true;
$includeChart = true;
require __DIR__ . '/_app_head.php';
?>
</head>
<body>
<?php
$pageHeading = '☀️ ' . $config['app']['name'];
$activePage = 'dashboard';
$showLiveStats = true;
require __DIR__ . '/_topbar.php';
?>

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
