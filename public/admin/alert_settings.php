<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Auth;

$user = Auth::currentUser();

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['plants'])) {
    $updated = 0;
    foreach ($_POST['plants'] as $plantId => $data) {
        $plantId = (int) $plantId;
        if ($plantId <= 0) continue;

        $threshold = (float) ($data['threshold'] ?? 0.70);
        $threshold = max(0.0, min(1.0, $threshold));

        Database::pdo()->prepare(
            'UPDATE plants SET underperform_threshold = ? WHERE id = ?'
        )->execute([$threshold, $plantId]);
        $updated++;
    }
    $msg = "✓ Uloženo $updated FVE";
}

$plants = Database::all(
    'SELECT id, code, name, peak_power_kwp, supported, support_type, underperform_threshold
     FROM plants WHERE is_active = 1 ORDER BY name'
);

// Posledních 7 dní statistika pro každou FVE
$now = (new DateTimeImmutable('now'))->format('Y-m-d');
$weekAgo = (new DateTimeImmutable('-7 days'))->format('Y-m-d');

$stats = [];
foreach ($plants as $p) {
    $row = Database::one(
        'SELECT COALESCE(SUM(energy_kwh), 0) AS sum_kwh, COUNT(*) AS days
         FROM production_daily
         WHERE plant_id = ? AND day BETWEEN ? AND ?',
        [$p['id'], $weekAgo, $now]
    );
    $stats[(int)$p['id']] = $row;
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>⚙️ Nastavení alertů — FVE Monitor</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .alert-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.88rem;
        }
        .alert-table th {
            background: var(--surface-2);
            padding: 10px;
            text-align: left;
            color: var(--text-dim);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .alert-table td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .alert-table tbody tr:hover { background: var(--surface-2); }
        .threshold-input {
            width: 80px;
            padding: 6px 8px;
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 4px;
            text-align: center;
            font-family: monospace;
            font-size: 0.95rem;
        }
        .threshold-input:focus { outline: none; border-color: var(--accent); }
        .preset-btns { display: flex; gap: 4px; margin-top: 6px; }
        .preset-btn {
            padding: 3px 8px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 3px;
            color: var(--text-dim);
            cursor: pointer;
            font-size: 0.72rem;
        }
        .preset-btn:hover { border-color: var(--accent); color: var(--text); }
        .help-box {
            background: rgba(245, 184, 0, 0.06);
            border-left: 3px solid var(--accent);
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.88rem;
        }
        .help-box code { background: var(--surface-2); padding: 2px 6px; border-radius: 3px; }
        .stat-cell {
            font-family: monospace;
            font-size: 0.82rem;
        }
        .ratio-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.82rem;
        }
        .ratio-good { background: rgba(63, 185, 80, 0.2); color: var(--good); }
        .ratio-warn { background: rgba(245, 184, 0, 0.2); color: var(--warn); }
        .ratio-bad  { background: rgba(248, 81, 73, 0.2); color: var(--bad); }
        .pill-supported {
            background: rgba(63, 185, 80, 0.15);
            color: var(--good);
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
        }
        .pill-no-support {
            background: var(--surface-2);
            color: var(--text-dim);
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
        }
    </style>
</head>
<body>
<header class="topbar">
    <h1>⚙️ Nastavení underperform alertů</h1>
    <div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
        <a href="index.php" style="color:var(--text-dim)">← Admin</a>
        · <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?>
    </div>
</header>

<main>
    <?php if ($msg): ?>
        <div class="help-box" style="border-color:var(--good);color:var(--good)"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="help-box">
        <strong>📐 Jak funguje underperform alert:</strong><br>
        Každý den ve <strong>23:55</strong> se pro každou FVE spočítá <code>actual / PVGIS</code> za posledních 7 dní.
        Pokud je výsledek <strong>pod thresholdem</strong>, vytvoří se warning alert.
        <br><br>
        <strong>Doporučené hodnoty:</strong><br>
        • <strong>0.70 (70%)</strong> — pro FVE s plným exportem (Vestec, Č. Lípa)<br>
        • <strong>0.40 (40%)</strong> — pro FVE s omezenými přebytky (Albert hypermarkety)<br>
        • <strong>0.30 (30%)</strong> — pro FVE s velmi omezeným exportem (mimořádně malá spotřeba objektu)<br>
        <br>
        <em>Pozn.: severity = critical pokud ratio < 0.4, jinak warning. Threshold neovlivňuje severity, jen zda alert vznikne.</em>
    </div>

    <form method="post">
        <table class="alert-table">
            <thead>
                <tr>
                    <th>Elektrárna</th>
                    <th>Podpora</th>
                    <th>Posledních 7 dní</th>
                    <th>Threshold (0.0-1.0)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plants as $p):
                    $s = $stats[(int)$p['id']];
                    $sumKwh = (float) $s['sum_kwh'];
                    $days   = (int) $s['days'];
                ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($p['name']) ?></strong>
                            <div style="font-size:0.72rem;color:var(--text-dim)">
                                <?= htmlspecialchars($p['code']) ?>
                                · <?= number_format((float)$p['peak_power_kwp'], 0, ',', ' ') ?> kWp
                            </div>
                        </td>
                        <td>
                            <?php if ($p['supported']): ?>
                                <span class="pill-supported">✓ <?= htmlspecialchars($p['support_type'] ?? 'ano') ?></span>
                            <?php else: ?>
                                <span class="pill-no-support">bez podpory</span>
                            <?php endif; ?>
                        </td>
                        <td class="stat-cell">
                            <?= number_format($sumKwh, 0, ',', ' ') ?> kWh
                            <small style="color:var(--text-dim);display:block">(<?= $days ?> dní)</small>
                        </td>
                        <td>
                            <input type="number" class="threshold-input"
                                   name="plants[<?= $p['id'] ?>][threshold]"
                                   value="<?= number_format((float)$p['underperform_threshold'], 2, '.', '') ?>"
                                   min="0.00" max="1.00" step="0.05">
                            <span style="margin-left:6px;color:var(--text-dim);font-size:0.85rem">
                                = <?= round((float)$p['underperform_threshold'] * 100) ?> %
                            </span>
                            <div class="preset-btns">
                                <button type="button" class="preset-btn" onclick="setThreshold(<?= $p['id'] ?>, 0.30)">30%</button>
                                <button type="button" class="preset-btn" onclick="setThreshold(<?= $p['id'] ?>, 0.40)">40%</button>
                                <button type="button" class="preset-btn" onclick="setThreshold(<?= $p['id'] ?>, 0.50)">50%</button>
                                <button type="button" class="preset-btn" onclick="setThreshold(<?= $p['id'] ?>, 0.70)">70%</button>
                                <button type="button" class="preset-btn" onclick="setThreshold(<?= $p['id'] ?>, 0.90)">90%</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:1rem;display:flex;gap:10px;align-items:center">
            <button type="submit" style="padding:10px 24px;background:var(--accent);color:#000;border:none;border-radius:4px;font-weight:600;cursor:pointer">
                💾 Uložit
            </button>
            <span style="color:var(--text-dim);font-size:0.85rem">
                Změna se projeví při dalším spuštění cronu (denně ve 23:55).
            </span>
        </div>
    </form>
</main>

<script>
function setThreshold(plantId, value) {
    const input = document.querySelector(`input[name="plants[${plantId}][threshold]"]`);
    if (input) {
        input.value = value.toFixed(2);
        // Vyvolaj input event aby se aktualizoval text vedle
        input.dispatchEvent(new Event('input'));
    }
}

// Live update procent vedle inputu
document.querySelectorAll('.threshold-input').forEach(inp => {
    inp.addEventListener('input', e => {
        const pct = Math.round(parseFloat(e.target.value || 0) * 100);
        const span = e.target.parentElement.querySelector('span');
        if (span) span.textContent = '= ' + pct + ' %';
    });
});
</script>
</body>
</html>
