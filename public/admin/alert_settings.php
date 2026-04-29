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
                                <button type="button" class="preset-btn test-btn" onclick="testAlert(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')" style="margin-left:10px;border-color:var(--accent);color:var(--accent)">▶️ Test</button>
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

<!-- Modal pro test alert -->
<div id="test-modal" onclick="closeTestModal(event)" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;padding:1rem">
    <div onclick="event.stopPropagation()" style="background:var(--surface);border:1px solid var(--border);border-radius:8px;max-width:700px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.5)">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--surface-2);border-radius:8px 8px 0 0">
            <h2 id="test-modal-title" style="margin:0;font-size:1.1rem;color:var(--accent)">Test underperform alertu</h2>
            <button onclick="closeTestModal()" style="background:transparent;border:none;color:var(--text);font-size:1.5rem;cursor:pointer;padding:0 6px;line-height:1">×</button>
        </div>
        <div id="test-modal-body" style="padding:1.25rem"></div>
    </div>
</div>

<script>
async function testAlert(plantId, plantName) {
    const modal = document.getElementById('test-modal');
    const title = document.getElementById('test-modal-title');
    const body = document.getElementById('test-modal-body');

    title.textContent = `Test alertu · ${plantName}`;
    body.innerHTML = '<div style="text-align:center;padding:1.5rem;opacity:0.7">Načítám...</div>';
    modal.style.display = 'flex';

    try {
        const r = await fetch(`/api.php?action=test_alert&plant=${plantId}`);
        const d = await r.json();

        if (d.error) {
            body.innerHTML = `<div style="color:var(--bad)">⚠️ ${d.error}</div>`;
            return;
        }

        const ratioPct = (d.ratio * 100).toFixed(1);
        const thresholdPct = (d.threshold * 100).toFixed(0);
        const wouldAlert = d.would_alert;
        const severity = d.severity;
        const sevColor = severity === 'critical' ? 'var(--bad)' : 'var(--warn)';

        const verdict = wouldAlert
            ? `<div style="background:rgba(248,81,73,0.15);border-left:3px solid ${sevColor};padding:14px;border-radius:4px;margin-bottom:1rem">
                <strong style="color:${sevColor};font-size:1.05rem">⚠️ ALERT BY VZNIKL</strong> (severity: <strong>${severity}</strong>)<br>
                <small style="opacity:0.85">Ratio ${ratioPct}% je pod thresholdem ${thresholdPct}%.</small>
               </div>`
            : `<div style="background:rgba(63,185,80,0.15);border-left:3px solid var(--good);padding:14px;border-radius:4px;margin-bottom:1rem">
                <strong style="color:var(--good);font-size:1.05rem">✅ ALERT BY NEVZNIKL</strong><br>
                <small style="opacity:0.85">Ratio ${ratioPct}% je nad thresholdem ${thresholdPct}%.</small>
               </div>`;

        const dayRows = d.per_day.map(day => {
            const dayPct = (day.ratio * 100).toFixed(1);
            const cls = day.ratio < d.threshold ? 'ratio-bad' : (day.ratio < 0.7 ? 'ratio-warn' : 'ratio-good');
            return `
                <tr>
                    <td style="padding:6px 8px;border-bottom:1px solid var(--border);font-family:monospace">${day.day}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid var(--border);text-align:right;font-family:monospace">${day.actual.toFixed(1)} kWh</td>
                    <td style="padding:6px 8px;border-bottom:1px solid var(--border);text-align:right;font-family:monospace;opacity:0.7">${day.expected.toFixed(1)} kWh</td>
                    <td style="padding:6px 8px;border-bottom:1px solid var(--border);text-align:right;font-family:monospace">
                        <span class="ratio-pill ${cls}">${dayPct}%</span>
                    </td>
                </tr>
            `;
        }).join('');

        body.innerHTML = `
            ${verdict}

            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.75rem;margin-bottom:1rem">
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.75rem">
                    <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Reálná výroba</div>
                    <div style="font-size:1.25rem;font-weight:700;font-family:monospace;color:var(--accent)">${d.actual.toLocaleString('cs-CZ')} <span style="font-size:0.7rem;opacity:0.65">kWh</span></div>
                </div>
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.75rem">
                    <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">PVGIS očekávaná</div>
                    <div style="font-size:1.25rem;font-weight:700;font-family:monospace">${d.expected.toLocaleString('cs-CZ')} <span style="font-size:0.7rem;opacity:0.65">kWh</span></div>
                </div>
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.75rem">
                    <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Ratio (actual/PVGIS)</div>
                    <div style="font-size:1.25rem;font-weight:700;font-family:monospace;color:${wouldAlert ? sevColor : 'var(--good)'}">${ratioPct} %</div>
                </div>
                <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:0.75rem">
                    <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Threshold</div>
                    <div style="font-size:1.25rem;font-weight:700;font-family:monospace">${thresholdPct} %</div>
                </div>
            </div>

            <h3 style="font-size:0.95rem;color:var(--text);margin:1rem 0 0.5rem 0;padding-bottom:4px;border-bottom:1px solid var(--border)">📅 Per-day breakdown (${d.days} dní)</h3>
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem">
                <thead>
                    <tr>
                        <th style="padding:8px;text-align:left;border-bottom:1px solid var(--border);color:var(--text-dim);font-size:0.72rem;text-transform:uppercase">Den</th>
                        <th style="padding:8px;text-align:right;border-bottom:1px solid var(--border);color:var(--text-dim);font-size:0.72rem;text-transform:uppercase">Reálná</th>
                        <th style="padding:8px;text-align:right;border-bottom:1px solid var(--border);color:var(--text-dim);font-size:0.72rem;text-transform:uppercase">PVGIS</th>
                        <th style="padding:8px;text-align:right;border-bottom:1px solid var(--border);color:var(--text-dim);font-size:0.72rem;text-transform:uppercase">Ratio</th>
                    </tr>
                </thead>
                <tbody>${dayRows}</tbody>
            </table>

            <div style="margin-top:1rem;padding:10px 14px;background:var(--surface-2);border-radius:4px;font-size:0.82rem;color:var(--text-dim)">
                💡 <em>Toto je <strong>simulace</strong> — žádný reálný alert nebyl vytvořen. Cron 23:55 zítra spustí stejnou logiku, ale s vytvořením alertu pokud ratio &lt; threshold.</em>
            </div>
        `;
    } catch (e) {
        body.innerHTML = `<div style="color:var(--bad)">Chyba: ${e.message}</div>`;
    }
}

function closeTestModal(event) {
    if (event && event.target.id !== 'test-modal') return;
    document.getElementById('test-modal').style.display = 'none';
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.getElementById('test-modal').style.display = 'none';
    }
});
</script>

</body>
</html>
