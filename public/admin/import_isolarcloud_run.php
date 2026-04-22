<?php
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\ISolarCloudProvider;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('POST only');
}

$selectedIds = $_POST['import_ids'] ?? [];
if (empty($selectedIds)) {
    header('Location: import_isolarcloud.php');
    exit;
}

$results = ['created' => [], 'updated' => [], 'errors' => []];

try {
    $provider = new ISolarCloudProvider();
    $stations = $provider->listPowerStations();
    $byPsId = [];
    foreach ($stations as $s) $byPsId[$s['ps_id']] = $s;

    $pdo = Database::pdo();

    foreach ($selectedIds as $psId) {
        if (!isset($byPsId[$psId])) {
            $results['errors'][] = "ps_id $psId není v iSolarCloud";
            continue;
        }
        $s = $byPsId[$psId];

        try {
            // Existuje v DB?
            $existing = Database::one(
                "SELECT id, code FROM plants WHERE provider = 'isolarcloud' AND provider_ps_id = ?",
                [$psId]
            );

            // Generuj kód z ps_name (např. VEST-01-CZ → VEST_01_CZ)
            $code = strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', $s['ps_name']));
            // Pokud kód koliduje s jiným záznamem, přidej ps_id
            if ($existing === null) {
                $codeExists = Database::one('SELECT id FROM plants WHERE code = ?', [$code]);
                if ($codeExists !== null) $code = $code . '_' . $psId;
            } else {
                $code = $existing['code']; // zachovej původní kód
            }

            // Install rok
            $installYear = null;
            if (!empty($s['install_date'])) {
                $installYear = (int) substr($s['install_date'], 0, 4);
            }

            // GPS - pokud je nesmyslné, uložíme defaultní (Praha) a uživatel doplní
            $lat = $s['latitude'];
            $lon = $s['longitude'];
            if (abs($lat) < 1 || abs($lon) < 1) {
                $lat = 50.0755;
                $lon = 14.4378; // Praha
            }

            if ($existing === null) {
                // INSERT
                $stmt = $pdo->prepare(
                    'INSERT INTO plants
                        (code, name, provider, provider_ps_id, latitude, longitude,
                         peak_power_kwp, tilt_deg, azimuth_deg, system_loss_pct,
                         install_year, degradation_pct_per_year, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 35, 0, 14.0, ?, 0.5, 1)'
                );
                $stmt->execute([
                    $code, $s['ps_name'], 'isolarcloud', $psId,
                    $lat, $lon, $s['peak_power_kwp'], $installYear,
                ]);
                $newId = (int) $pdo->lastInsertId();

                // Vytvoř default sekci (1 sekce, 35° jih, 100%)
                $pdo->prepare(
                    "INSERT INTO plant_sections (plant_id, name, tilt_deg, azimuth_deg, power_share_pct, sort_order)
                     VALUES (?, 'Hlavní', 35, 0, 100.00, 0)"
                )->execute([$newId]);

                $results['created'][] = ['code' => $code, 'name' => $s['ps_name']];
            } else {
                // UPDATE - jen fakta, NEPŘEPISOVAT sekce
                $stmt = $pdo->prepare(
                    'UPDATE plants SET
                        name = ?, latitude = ?, longitude = ?,
                        peak_power_kwp = ?, install_year = COALESCE(install_year, ?)
                     WHERE id = ?'
                );
                $stmt->execute([
                    $s['ps_name'], $lat, $lon, $s['peak_power_kwp'],
                    $installYear, $existing['id'],
                ]);

                $results['updated'][] = ['code' => $code, 'name' => $s['ps_name']];
            }
        } catch (\Throwable $e) {
            $results['errors'][] = "{$s['ps_name']}: " . $e->getMessage();
        }
    }
} catch (\Throwable $e) {
    $results['errors'][] = 'Globální chyba: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Import dokončen — Admin</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="admin.css">
</head>
<body>
<header class="topbar">
    <h1>✓ Import dokončen</h1>
    <div class="topbar-meta">
        <a href="index.php" class="btn btn-primary">← Seznam elektráren</a>
    </div>
</header>

<main>
    <div class="form-card">
        <h3>Souhrn</h3>
        <p>🆕 <strong><?= count($results['created']) ?></strong> nových elektráren vytvořeno</p>
        <p>🔄 <strong><?= count($results['updated']) ?></strong> aktualizováno</p>
        <?php if (!empty($results['errors'])): ?>
            <p>⚠ <strong><?= count($results['errors']) ?></strong> chyb</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($results['created'])): ?>
    <div class="form-card">
        <h3>Vytvořené</h3>
        <ul style="list-style:none;padding:0">
        <?php foreach ($results['created'] as $r): ?>
            <li><code><?= htmlspecialchars($r['code']) ?></code> — <?= htmlspecialchars($r['name']) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['updated'])): ?>
    <div class="form-card">
        <h3>Aktualizované</h3>
        <ul style="list-style:none;padding:0">
        <?php foreach ($results['updated'] as $r): ?>
            <li><code><?= htmlspecialchars($r['code']) ?></code> — <?= htmlspecialchars($r['name']) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['errors'])): ?>
    <div class="form-card">
        <h3>Chyby</h3>
        <ul style="list-style:none;padding:0;color:var(--bad)">
        <?php foreach ($results['errors'] as $e): ?>
            <li>⚠ <?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="form-card">
        <h3>Další kroky</h3>
        <ol style="margin-left:1.5rem;color:var(--text-dim)">
            <li>Otevři každou novou elektrárnu a doplň <strong>sekce panelů</strong> (sklon, azimut, podíl) podle reálné instalace</li>
            <li>U elektrárny <strong>ZLIN-01-CZ</strong> (chybí GPS v iSolarCloud) doplň přes mapu</li>
            <li>Spusť ⟳ <strong>PVGIS refresh</strong> pro výpočet predikcí</li>
        </ol>
    </div>
</main>
</body>
</html>
