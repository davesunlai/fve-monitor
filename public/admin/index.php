<?php
/**
 * Admin — seznam elektráren
 */
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;

$plants = Database::all(
    'SELECT p.*,
            (SELECT COUNT(*) FROM plant_sections WHERE plant_id = p.id) AS sections_count,
            (SELECT COUNT(*) FROM pvgis_monthly WHERE plant_id = p.id) AS pvgis_rows
     FROM plants p
     ORDER BY p.name'
);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — FVE Monitor</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="admin.css">
</head>
<body>
<header class="topbar">
    <?php $u = \FveMonitor\Lib\Auth::currentUser(); ?>
    <h1>⚙️ FVE Monitor — Admin</h1>
    <div class="topbar-meta">
        <a href="../" class="btn btn-ghost">← Dashboard</a>
        <a href="alerts_history.php" class="btn">📋 Historie alertů</a>
        <a href="plants_ote.php" class="btn">🏛️ OTE/ERÚ</a>
        <a href="ote_report.php" class="btn">📊 Měsíční výkaz</a>
        <a href="import_isolarcloud.php" class="btn">⬇ Importovat z iSolarCloud</a>
        <a href="plant_edit.php" class="btn btn-primary">+ Nová elektrárna</a>
    </div>
<div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
            <?= htmlspecialchars($u['full_name'] ?? $u['username'] ?? '') ?>
            · <a href="profile.php" style="color:var(--text-dim)">Profil</a>
            · <a href="logout.php" style="color:var(--text-dim)">Odhlásit</a>
        </div>
    </header>

<main>
    <?php if (!empty($_GET['msg'])): ?>
        <div style="background:var(--surface);border-left:4px solid var(--accent);padding:12px 16px;margin-bottom:1rem;border-radius:4px">
            <?= htmlspecialchars($_GET['msg']) ?>
        </div>
    <?php endif; ?>

    <h2>Elektrárny (<?= count($plants) ?>)</h2>

    <?php if (empty($plants)): ?>
        <div class="empty-state">
            <p>Zatím žádné elektrárny. <a href="plant_edit.php">Přidat první</a>.</p>
        </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Kód</th>
                <th>Název</th>
                <th>Provider</th>
                <th>Výkon</th>
                <th>Lokace</th>
                <th>Sekce</th>
                <th>PVGIS</th>
                <th>Stav</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($plants as $p): ?>
            <tr>
                <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                <td><span class="badge badge-<?= $p['provider'] ?>"><?= htmlspecialchars($p['provider']) ?></span></td>
                <td><?= $p['peak_power_kwp'] ?> kWp</td>
                <td><small><?= $p['latitude'] ?>, <?= $p['longitude'] ?></small></td>
                <td><?= $p['sections_count'] ?></td>
                <td>
                    <?php if ($p['pvgis_rows'] > 0): ?>
                        <span class="badge badge-ok">✓ <?= $p['pvgis_rows'] ?></span>
                    <?php else: ?>
                        <span class="badge badge-warn">chybí</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($p['is_active']): ?>
                        <span class="badge badge-ok">aktivní</span>
                    <?php else: ?>
                        <span class="badge badge-dim">neaktivní</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <a href="plant_edit.php?id=<?= $p['id'] ?>" class="btn btn-sm">Upravit</a>
                    <a href="pvgis_refresh.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-ghost"
                       onclick="return confirm('Obnovit PVGIS predikce pro tuto elektrárnu?')">⟳ PVGIS</a>
                    <a href="test_alarm.php?plant_id=<?= $p['id'] ?>" class="btn btn-sm btn-ghost"
                       onclick="return confirm('Vytvořit testovací alarm a rozeslat push notifikace?')" title="Test push notifikace">🧪 Test</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</main>
</body>
</html>
