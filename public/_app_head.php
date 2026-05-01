<?php
/**
 * Společný <head> pro všechny stránky.
 *
 * Parametry (před require):
 *   $pageTitle      - title v hlavičce, např. "📊 Plnění FVE"
 *   $includeLeaflet - bool, default false (jen index.php má mapu)
 *   $includeChart   - bool, default true (Chart.js skoro všude)
 */
$config = require __DIR__ . '/../config/config.php';
$pageTitle      = $pageTitle ?? $config['app']['name'];
$includeLeaflet = $includeLeaflet ?? false;
$includeChart   = $includeChart ?? true;
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
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
<?php if ($includeLeaflet): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<?php endif; ?>
<?php if ($includeChart): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php endif; ?>
