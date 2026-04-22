<?php
/**
 * Admin — import elektráren z iSolarCloud
 *
 * Načte seznam přes ISolarCloudProvider::listPowerStations()
 * a porovná s DB (provider_ps_id) - rozdělí na NOVÉ / UPDATE / SKIP.
 */
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\ISolarCloudProvider;

$errors = [];
$stations = [];

try {
    $provider = new ISolarCloudProvider();
    $stations = $provider->listPowerStations();
} catch (\Throwable $e) {
    $errors[] = $e->getMessage();
}

// Načti existující plants podle provider_ps_id
$existingMap = [];
if (!empty($stations)) {
    $rows = Database::all(
        "SELECT id, code, name, provider_ps_id, peak_power_kwp, latitude, longitude
         FROM plants WHERE provider = 'isolarcloud' AND provider_ps_id IS NOT NULL"
    );
    foreach ($rows as $r) {
        $existingMap[$r['provider_ps_id']] = $r;
    }
}

// Klasifikuj
$counts = ['new' => 0, 'update' => 0, 'noGps' => 0];
foreach ($stations as &$s) {
    $existing = $existingMap[$s['ps_id']] ?? null;
    $s['_status'] = $existing === null ? 'new' : 'update';
    $s['_existing'] = $existing;
    $s['_hasGps'] = abs($s['latitude']) > 1 && abs($s['longitude']) > 1;
    if (!$s['_hasGps']) $counts['noGps']++;
    if ($s['_status'] === 'new') $counts['new']++;
    else $counts['update']++;
}
unset($s);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Import z iSolarCloud — Admin</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="admin.css">
</head>
<body>
<header class="topbar">
    <h1>⬇ Import z iSolarCloud</h1>
    <div class="topbar-meta">
        <a href="index.php" class="btn btn-ghost">← Zpět na seznam</a>
    </div>
</header>

<main>
    <?php foreach ($errors as $e): ?>
        <div class="notice notice-error">⚠ <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php if (empty($errors) && empty($stations)): ?>
        <div class="empty-state">Žádné elektrárny nenalezeny.</div>
    <?php elseif (!empty($stations)): ?>

    <div class="form-card" style="margin-bottom:1rem">
        <h3>📋 Náhled — <?= count($stations) ?> elektráren v iSolarCloud</h3>
        <p style="color:var(--text-dim);font-size:.9rem;margin-top:.5rem">
            🆕 <strong><?= $counts['new'] ?></strong> nových
            · 🔄 <strong><?= $counts['update'] ?></strong> aktualizace existujících
            <?php if ($counts['noGps'] > 0): ?>
                · ⚠ <strong><?= $counts['noGps'] ?></strong> bez GPS (doplníš ručně)
            <?php endif; ?>
        </p>
        <p style="color:var(--text-dim);font-size:.85rem;margin-top:.5rem">
            <strong>Co se importuje:</strong> ps_id, název, výkon (kWp), GPS, datum instalace.<br>
            <strong>Co zůstane:</strong> sekce panelů (sklon/azimut), ztráty systému, degradace.
        </p>
    </div>

    <form method="post" action="import_isolarcloud_run.php">
        <table class="admin-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="check-all" checked></th>
                    <th>Stav</th>
                    <th>iSolarCloud</th>
                    <th>Lokace</th>
                    <th>Výkon</th>
                    <th>Total kWh</th>
                    <th>Existující v DB</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stations as $s): ?>
                <?php
                $st = $s['_status'];
                $ex = $s['_existing'];
                $statusBadge = $st === 'new'
                    ? '<span class="badge badge-ok">🆕 nová</span>'
                    : '<span class="badge badge-mock">🔄 update</span>';
                ?>
                <tr>
                    <td>
                        <input type="checkbox" name="import_ids[]"
                               value="<?= htmlspecialchars($s['ps_id']) ?>" checked>
                    </td>
                    <td><?= $statusBadge ?>
                        <?php if (!$s['_hasGps']): ?>
                            <br><small style="color:var(--warn)">⚠ chybí GPS</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($s['ps_name']) ?></strong>
                        <br><small style="color:var(--text-dim)">ps_id: <?= htmlspecialchars($s['ps_id']) ?></small>
                    </td>
                    <td>
                        <small><?= htmlspecialchars($s['ps_location']) ?></small>
                        <?php if ($s['_hasGps']): ?>
                            <br><small style="color:var(--text-dim)">
                                <?= number_format($s['latitude'], 4) ?>, <?= number_format($s['longitude'], 4) ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format($s['peak_power_kwp'], 1) ?> kWp</td>
                    <td>
                        <small style="color:var(--text-dim)">
                            <?= number_format($s['total_energy_kwh'] / 1000, 1) ?> MWh
                        </small>
                    </td>
                    <td>
                        <?php if ($ex !== null): ?>
                            <code><?= htmlspecialchars($ex['code']) ?></code>
                            <br><small style="color:var(--text-dim)"><?= htmlspecialchars($ex['name']) ?></small>
                        <?php else: ?>
                            <small style="color:var(--text-dim)">—</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">⬇ Importovat vybrané</button>
            <a href="index.php" class="btn btn-ghost">Zrušit</a>
        </div>
    </form>
    <?php endif; ?>
</main>

<script>
document.getElementById('check-all')?.addEventListener('change', function() {
    document.querySelectorAll('input[name="import_ids[]"]').forEach(cb => cb.checked = this.checked);
});
</script>
</body>
</html>
