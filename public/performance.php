<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Auth;
use FveMonitor\Lib\Database;

if (!Auth::isLoggedIn()) {
    header('Location: /admin/login.php');
    exit;
}

$user = Auth::currentUser();

$year  = (int) ($_GET['year']  ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) $month = (int) date('n');

// FVE seznam s PVGIS daty
$plants = Database::all(
    "SELECT id, code, name, peak_power_kwp, evid_number, address_city
     FROM plants WHERE is_active = 1
     ORDER BY COALESCE(evid_number, 99), name"
);

// Měsíční výroba per FVE pro vybraný rok
$monthlyData = []; // [plant_id][month] => kwh
$rows = Database::all(
    "SELECT plant_id, MONTH(day) AS month, SUM(energy_kwh) AS kwh
     FROM production_daily
     WHERE YEAR(day) = ?
     GROUP BY plant_id, MONTH(day)",
    [$year]
);
foreach ($rows as $r) {
    $monthlyData[(int)$r['plant_id']][(int)$r['month']] = (float)$r['kwh'];
}

// PVGIS predikce per FVE per měsíc
$pvgisData = []; // [plant_id][month] => kwh
$pvg = Database::all(
    "SELECT plant_id, month, SUM(e_m_kwh) AS kwh
     FROM pvgis_monthly
     GROUP BY plant_id, month"
);
foreach ($pvg as $p) {
    $pvgisData[(int)$p['plant_id']][(int)$p['month']] = (float)$p['kwh'];
}

// Výpočty per FVE
$tableData = [];
foreach ($plants as $p) {
    $pid = (int)$p['id'];
    $kwp = (float)$p['peak_power_kwp'];

    $monthKwh    = $monthlyData[$pid][$month] ?? 0;
    $monthPvgis  = $pvgisData[$pid][$month] ?? 0;
    $monthRatio  = $monthPvgis > 0 ? ($monthKwh / $monthPvgis) * 100 : 0;
    $monthProd   = $kwp > 0 ? $monthKwh / $kwp : 0;

    // Kumulativní za rok do vybraného měsíce
    $cumKwh = 0; $cumPvgis = 0;
    for ($m = 1; $m <= $month; $m++) {
        $cumKwh   += $monthlyData[$pid][$m] ?? 0;
        $cumPvgis += $pvgisData[$pid][$m] ?? 0;
    }
    $cumRatio = $cumPvgis > 0 ? ($cumKwh / $cumPvgis) * 100 : 0;

    // Sparkline data - posledních 12 měsíců plnění %
    $sparkline = [];
    for ($m = 1; $m <= 12; $m++) {
        $aKwh = $monthlyData[$pid][$m] ?? 0;
        $aPv  = $pvgisData[$pid][$m] ?? 0;
        $sparkline[] = $aPv > 0 ? round(($aKwh / $aPv) * 100, 1) : 0;
    }

    $tableData[] = [
        'plant'       => $p,
        'month_kwh'   => $monthKwh,
        'month_pvgis' => $monthPvgis,
        'month_ratio' => $monthRatio,
        'month_prod'  => $monthProd,
        'cum_kwh'     => $cumKwh,
        'cum_pvgis'   => $cumPvgis,
        'cum_ratio'   => $cumRatio,
        'sparkline'   => $sparkline,
    ];
}

// Seřaď podle plnění % desc (nejlepší nahoře)
usort($tableData, fn($a, $b) => $b['month_ratio'] <=> $a['month_ratio']);

// Roky pro tlačítka
$years = [];
$yearRows = Database::all("SELECT DISTINCT YEAR(day) AS y FROM production_daily ORDER BY y DESC");
foreach ($yearRows as $y) $years[] = (int)$y['y'];
if (!in_array((int)date('Y'), $years, true)) $years[] = (int)date('Y');
sort($years);

$months = ['','leden','únor','březen','duben','květen','červen','červenec','srpen','září','říjen','listopad','prosinec'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<?php
$pageTitle = '📊 Plnění FVE — ' . ($config['app']['name'] ?? 'FVE Monitor');
$includeChart = true;
require __DIR__ . '/_app_head.php';
?>
<style>

.perf-filter {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
}
.year-tabs-perf {
    display: flex;
    gap: 4px;
}
.year-tab {
    padding: 6px 14px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text-dim);
    text-decoration: none;
    font-size: 0.88rem;
}
.year-tab.active {
    background: var(--accent);
    color: #000;
    font-weight: 700;
    border-color: var(--accent);
}

.perf-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--surface);
    font-size: 0.85rem;
}
.perf-table th, .perf-table td {
    padding: 8px;
    border-bottom: 1px solid var(--border);
    text-align: right;
}
.perf-table th {
    background: var(--surface-2);
    color: var(--text-dim);
    font-size: 0.78rem;
    letter-spacing: 0.2px;
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
}
.perf-table th small {
    text-transform: lowercase;
}
.perf-table th.col-name, .perf-table td.col-name {
    text-align: left;
    position: sticky;
    left: 0;
    background: var(--surface);
    z-index: 1;
    border-right: 2px solid var(--border);
    min-width: 180px;
}
.perf-table thead th.col-name { background: var(--surface-2); }
.perf-table th.col-evid, .perf-table td.col-evid {
    text-align: center;
    position: sticky;
    left: 180px;
    background: var(--surface);
    z-index: 1;
    border-right: 1px solid var(--border);
    width: 40px;
}
.perf-table thead th.col-evid { background: var(--surface-2); }
.perf-table tbody tr:hover td.col-name,
.perf-table tbody tr:hover td.col-evid { background: var(--surface-2); }

.evid-badge {
    display: inline-block;
    width: 26px;
    height: 26px;
    line-height: 26px;
    background: var(--surface-2);
    border: 1px solid var(--accent);
    color: var(--accent);
    border-radius: 50%;
    font-weight: 700;
    font-family: monospace;
}
.plant-name { font-weight: 600; }
.plant-meta { font-size: 0.72rem; color: var(--text-dim); }

.perf-num { font-family: monospace; }
.perf-pct {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.85rem;
    font-family: monospace;
}
.pct-good { background: rgba(63, 185, 80, 0.25); color: var(--good); }
.pct-warn { background: rgba(245, 184, 0, 0.25); color: var(--warn); }
.pct-bad  { background: rgba(248, 81, 73, 0.25); color: var(--bad); }

.sparkline-wrap {
    display: inline-block;
    width: 120px;
    height: 30px;
    vertical-align: middle;
}

.help-box {
    background: rgba(245, 184, 0, 0.06);
    border-left: 3px solid var(--accent);
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 1rem;
    font-size: 0.85rem;
}

.table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
}

</style>
</head>
<body>
<?php require __DIR__ . '/_topbar.php'; ?>

<main>
    <h1 style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem">
        📊 Plnění FVE
        <small style="font-weight:400;color:var(--text-dim);font-size:0.85rem">— skutečnost vs PVGIS predikce</small>
    </h1>

    <div class="help-box">
        <strong>Jak to funguje:</strong> Pro každou FVE se počítá <code>actual / PVGIS × 100 %</code> = <strong>plnění predikce</strong>.<br>
        FVE jsou <strong>seřazené podle plnění</strong> ve vybraném měsíci (nejlepší nahoře). 
        FVE s <strong>plným exportem</strong> (Vestec, Č.Lípa) typicky 70-100%, FVE s <strong>omezenými přebytky</strong> (Albert) typicky 30-60%.
    </div>

    <form method="get" class="perf-filter">
        <span><strong>📅 Rok:</strong></span>
        <div class="year-tabs-perf">
            <?php foreach ($years as $y): ?>
                <a href="?year=<?= $y ?>&month=<?= $month ?>" class="year-tab <?= $year === $y ? 'active' : '' ?>"><?= $y ?></a>
            <?php endforeach; ?>
        </div>

        <label style="margin-left:1rem"><strong>📆 Měsíc:</strong>
            <select name="month" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                        <?= ucfirst($months[$m]) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </label>
        <input type="hidden" name="year" value="<?= $year ?>">
    </form>

    <div class="table-wrap">
        <table class="perf-table">
            <thead>
                <tr>
                    <th class="col-name">FVE</th>
                    <th class="col-evid">#</th>
                    <th>Reálná výroba [kWh]<br><small style="font-weight:400;opacity:0.7"><?= $months[$month] ?> <?= $year ?></small></th>
                    <th>PVGIS [kWh]<br><small style="font-weight:400;opacity:0.7">predikce <?= $months[$month] ?></small></th>
                    <th>Plnění [%]<br><small style="font-weight:400;opacity:0.7">měsíc</small></th>
                    <th>Productivity [kWh/kWp]<br><small style="font-weight:400;opacity:0.7">měsíc</small></th>
                    <th>Σ od ledna [kWh]<br><small style="font-weight:400;opacity:0.7">kumulativně <?= $year ?></small></th>
                    <th>Σ PVGIS [kWh]<br><small style="font-weight:400;opacity:0.7">kumulativně</small></th>
                    <th>Roční plnění [%]</th>
                    <th>Plnění [%] · 12 měsíců</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableData as $row):
                    $p = $row['plant'];
                    $cls = $row['month_ratio'] >= 70 ? 'pct-good' : ($row['month_ratio'] >= 40 ? 'pct-warn' : 'pct-bad');
                    $clsCum = $row['cum_ratio'] >= 70 ? 'pct-good' : ($row['cum_ratio'] >= 40 ? 'pct-warn' : 'pct-bad');
                ?>
                    <tr>
                        <td class="col-name">
                            <div class="plant-name"><?= htmlspecialchars($p['name']) ?></div>
                            <div class="plant-meta">
                                <?= number_format((float)$p['peak_power_kwp'], 0, ',', ' ') ?> kWp
                                <?php if ($p['address_city']): ?> · <?= htmlspecialchars($p['address_city']) ?><?php endif; ?>
                            </div>
                        </td>
                        <td class="col-evid">
                            <?php if ($p['evid_number']): ?>
                                <span class="evid-badge"><?= $p['evid_number'] ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="perf-num"><?= number_format($row['month_kwh'], 0, ',', ' ') ?></td>
                        <td class="perf-num" style="opacity:0.75"><?= number_format($row['month_pvgis'], 0, ',', ' ') ?></td>
                        <td><span class="perf-pct <?= $cls ?>"><?= number_format($row['month_ratio'], 1, ',', '') ?> %</span></td>
                        <td class="perf-num"><?= number_format($row['month_prod'], 1, ',', '') ?></td>
                        <td class="perf-num"><?= number_format($row['cum_kwh'], 0, ',', ' ') ?></td>
                        <td class="perf-num" style="opacity:0.75"><?= number_format($row['cum_pvgis'], 0, ',', ' ') ?></td>
                        <td><span class="perf-pct <?= $clsCum ?>"><?= number_format($row['cum_ratio'], 1, ',', '') ?> %</span></td>
                        <td>
                            <canvas class="sparkline-wrap"
                                    data-values="<?= htmlspecialchars(json_encode($row['sparkline'])) ?>"></canvas>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top:1rem;padding:10px 14px;background:var(--surface-2);border-radius:4px;font-size:0.82rem;color:var(--text-dim)">
        💡 Roční graf plnění (12 měsíců × 10 sloupců) bude přidán v další verzi.
    </div>
</main>

<script>
// Render sparkline canvases (mini barchart)
document.querySelectorAll('.sparkline-wrap').forEach(canvas => {
    const values = JSON.parse(canvas.dataset.values || '[]');
    if (!values.length) return;

    const dpr = window.devicePixelRatio || 1;
    canvas.width = canvas.offsetWidth * dpr;
    canvas.height = canvas.offsetHeight * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    const w = canvas.offsetWidth;
    const h = canvas.offsetHeight;
    const max = 100; // Y-osa max = 100%
    const barW = (w - 12) / values.length * 0.7;
    const gap = (w - 12) / values.length * 0.3;

    // Vodorovná čára 100% (PVGIS strop)
    ctx.strokeStyle = 'rgba(245, 184, 0, 0.5)';
    ctx.setLineDash([2, 2]);
    ctx.lineWidth = 0.5;
    ctx.beginPath();
    ctx.moveTo(0, 2);
    ctx.lineTo(w, 2);
    ctx.stroke();
    ctx.setLineDash([]);

    values.forEach((v, i) => {
        const ratio = Math.min(v / max, 1.5); // ne víc než 150%
        const barH = ratio * (h - 4);
        const color = v >= 70 ? '#3fb950' : (v >= 40 ? '#f5b800' : '#f85149');
        ctx.fillStyle = color;
        ctx.fillRect(6 + i * (barW + gap), h - barH, barW, barH);
    });
});
</script>
</body>
</html>
