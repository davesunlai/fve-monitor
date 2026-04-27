<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Auth;

Auth::start();
if (!Auth::isLoggedIn()) {
    header('Location: admin/login.php');
    exit;
}
$user = Auth::currentUser();

// ─── Filter parametry ───
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));
$year = (int)($_GET['year'] ?? $now->format('Y'));
$month = (int)($_GET['month'] ?? $now->format('n'));
$threshold1 = (int)($_GET['t1'] ?? 25);
$threshold2 = (int)($_GET['t2'] ?? 50);
$mode = $_GET['mode'] ?? 'denni'; // denni | mesicni
if (!in_array($mode, ['denni', 'mesicni'], true)) $mode = 'denni';

$year = max(2024, min(2030, $year));
$month = max(1, min(12, $month));

// FVE: pokud nikdo nezaškrtl nic, použij všechny aktivní
$allPlants = Database::all(
    'SELECT id, name, peak_power_kwp FROM plants WHERE is_active = 1 ORDER BY name'
);

$rawSelected = $_GET['plants'] ?? null;
if ($rawSelected === null) {
    // Žádný parametr v URL = první otevření = všechny
    $selectedIds = array_map(fn($p) => (int)$p['id'], $allPlants);
} else {
    $selectedIds = array_map('intval', (array)$rawSelected);
}

// Filtruj seznam FVE
$plants = array_values(array_filter($allPlants, fn($p) => in_array((int)$p['id'], $selectedIds, true)));

// ─── Načti data ───
$periodFrom = sprintf('%04d-%02d-01', $year, $month);
$periodTo = (new DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');
$daysInMonth = (int)(new DateTimeImmutable($periodFrom))->format('t');

$dailyData = []; // [day][plant_id] = kwh

if (!empty($selectedIds)) {
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $sql = "SELECT DAY(day) AS d, plant_id, energy_kwh
            FROM production_daily
            WHERE day BETWEEN ? AND ?
            AND plant_id IN ($placeholders)";
    $params = array_merge([$periodFrom, $periodTo], $selectedIds);
    $rows = Database::all($sql, $params);
    foreach ($rows as $r) {
        $dailyData[(int)$r['d']][(int)$r['plant_id']] = (float)$r['energy_kwh'];
    }
}

// ─── Výpočet grid ───
$grid = [];
$plantTotals = [];
$plantProductivity = []; // Ø kWh/kWp za měsíc per FVE
foreach ($plants as $p) {
    $plantTotals[(int)$p['id']] = 0.0;
    $plantProductivity[(int)$p['id']] = ['sum' => 0.0, 'days' => 0];
}

$dailyAverages = []; // [day] => denní průměr productivity (přes FVE které měly data)

for ($d = 1; $d <= $daysInMonth; $d++) {
    // Productivity per FVE
    $productivities = [];
    foreach ($plants as $p) {
        $pid = (int)$p['id'];
        $kwh = $dailyData[$d][$pid] ?? null;
        $kwp = (float)$p['peak_power_kwp'];
        if ($kwh !== null && $kwp > 0) {
            $productivities[] = $kwh / $kwp;
        }
    }
    $avgProd = !empty($productivities) ? array_sum($productivities) / count($productivities) : 0;
    if ($avgProd > 0) $dailyAverages[$d] = $avgProd;

    foreach ($plants as $p) {
        $pid = (int)$p['id'];
        $kwh = $dailyData[$d][$pid] ?? null;
        $kwp = (float)$p['peak_power_kwp'];

        if ($kwh === null) {
            $grid[$d][$pid] = null;
            continue;
        }

        $plantTotals[$pid] += $kwh;
        $prod = $kwp > 0 ? $kwh / $kwp : 0;
        if ($prod > 0) {
            $plantProductivity[$pid]['sum'] += $prod;
            $plantProductivity[$pid]['days']++;
        }
        $deviationPct = ($avgProd > 0) ? (($prod - $avgProd) / $avgProd) * 100 : 0;

        $status = 'ok';
        if (abs($deviationPct) > $threshold2) $status = 'bad';
        elseif (abs($deviationPct) > $threshold1) $status = 'warn';

        $grid[$d][$pid] = [
            'kwh' => $kwh,
            'productivity' => $prod,
            'avg' => $avgProd,
            'deviation_pct' => $deviationPct,
            'status' => $status,
        ];
    }
}

// ─── CSV export ───
if (isset($_GET['export'])) {
    $filename = sprintf('FVE_porovnani_%04d_%02d.csv', $year, $month);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    $head = ['Den'];
    foreach ($plants as $p) {
        $head[] = $p['name'] . ' (kWh)';
        $head[] = $p['name'] . ' (odchylka %)';
    }
    fputcsv($out, $head, ';');

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $row = [sprintf('%02d.%02d.%04d', $d, $month, $year)];
        foreach ($plants as $p) {
            $cell = $grid[$d][(int)$p['id']] ?? null;
            if ($cell === null) {
                $row[] = ''; $row[] = '';
            } else {
                $row[] = number_format($cell['kwh'], 1, ',', '');
                $row[] = ($cell['deviation_pct'] >= 0 ? '+' : '') . number_format($cell['deviation_pct'], 1, ',', '');
            }
        }
        fputcsv($out, $row, ';');
    }

    $sumRow = ['Σ Celkem'];
    foreach ($plants as $p) {
        $sumRow[] = number_format($plantTotals[(int)$p['id']], 1, ',', '');
        $sumRow[] = '';
    }
    fputcsv($out, $sumRow, ';');
    fclose($out);
    exit;
}

// Měsíční benchmark = průměr denních průměrů (pro režim mesicni)
$monthlyBenchmark = !empty($dailyAverages) ? array_sum($dailyAverages) / count($dailyAverages) : 0;

$months = ['leden','únor','březen','duben','květen','červen','červenec','srpen','září','říjen','listopad','prosinec'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>📊 Denní srovnání FVE</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.filter-bar { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 1rem; }
.filter-row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; margin-bottom: 0.75rem; }
.filter-row:last-child { margin-bottom: 0; }
.filter-bar select, .filter-bar input[type="number"] { padding: 6px 10px; background: var(--surface-2); color: var(--text); border: 1px solid var(--border); border-radius: 4px; font-size: 0.88rem; }
.plants-checkboxes { display: flex; flex-wrap: wrap; gap: 6px; }
.plants-checkboxes label { display: flex; align-items: center; gap: 6px; padding: 6px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
.plants-checkboxes label:hover { background: var(--surface); }
.plants-checkboxes input[type="checkbox"] { margin: 0; cursor: pointer; }
.btn-primary { padding: 8px 18px; background: var(--accent); color: #000; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.9rem; }
.btn-secondary { padding: 8px 14px; background: var(--surface-2); color: var(--text); border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.btn-secondary:hover { border-color: var(--accent); }

.table-scroll { overflow-x: auto; border: 1px solid var(--border); border-radius: 6px; max-height: 75vh; }
.comparison-table { width: 100%; border-collapse: collapse; background: var(--surface); font-size: 0.82rem; }
.comparison-table th, .comparison-table td { padding: 6px 8px; text-align: center; border-bottom: 1px solid var(--border); border-right: 1px solid var(--border); }
.comparison-table th { background: var(--surface-2); color: var(--text-dim); font-size: 0.78rem; letter-spacing: 0.3px; white-space: nowrap; position: sticky; top: 0; z-index: 2; font-weight: 700; }
.comparison-table th div { text-transform: none; font-size: 0.72rem; opacity: 0.85; }
.comparison-table th.day-col { text-align: left; min-width: 50px; position: sticky; left: 0; z-index: 4; }
.comparison-table td.day-cell { background: var(--surface-2); font-weight: 600; text-align: left; position: sticky; left: 0; color: var(--text-dim); z-index: 1; }
.cell-ok    { background: rgba(63, 185, 80, 0.30); color: var(--text); }
.cell-warn  { background: rgba(245, 184, 0, 0.32); color: var(--text); }
.cell-bad   { background: rgba(248, 81, 73, 0.30); color: var(--text); }
.cell-nodata { color: var(--text-dim); background: var(--surface); }
.cell-kwh { font-weight: 700; font-size: 1rem; color: var(--text); }
.unit-suffix { font-size: 0.65rem; opacity: 0.55; font-weight: 500; margin-left: 2px; }
.cell-pct { font-size: 0.78rem; opacity: 0.95; display: block; margin-top: 3px; font-weight: 600; }

tfoot tr { background: var(--surface-2); font-weight: 700; }
tfoot td { border-top: 2px solid var(--border); }

.help-box { background: rgba(245, 184, 0, 0.06); border-left: 3px solid var(--accent); padding: 10px 14px; border-radius: 4px; margin-bottom: 1rem; font-size: 0.85rem; }
.help-box code { background: var(--surface-2); padding: 1px 6px; border-radius: 3px; }
.legend { display: flex; gap: 1rem; margin: 0.5rem 0; font-size: 0.85rem; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 4px; }
.legend-swatch { display: inline-block; width: 14px; height: 14px; border-radius: 3px; }

.comparison-table td.clickable { cursor: pointer; transition: filter 0.15s; }
.comparison-table td.clickable:hover { filter: brightness(1.3); outline: 2px solid var(--accent); outline-offset: -2px; }

/* Modal */
.day-modal {
    display: none;
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.day-modal.open { display: flex; }
.day-modal-content {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
}
.day-modal-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--surface-2);
    border-radius: 8px 8px 0 0;
}
.day-modal-header h2 {
    margin: 0;
    font-size: 1.1rem;
    color: var(--accent);
}
.day-modal-close {
    background: transparent;
    border: none;
    color: var(--text);
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0 6px;
    line-height: 1;
}
.day-modal-body { padding: 1.25rem; }
.modal-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.modal-stat {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.75rem;
}
.modal-stat-label {
    font-size: 0.72rem;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.modal-stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    font-family: monospace;
    color: var(--text);
}
.modal-stat-value.accent { color: var(--accent); }
.modal-stat-value.good { color: var(--good); }
.modal-stat-value.bad { color: var(--bad); }
.modal-stat-value.warn { color: var(--warn); }
.modal-stat-unit { font-size: 0.7rem; opacity: 0.65; margin-left: 4px; font-weight: 500; }

.modal-section { margin-top: 1rem; }
.modal-section h3 {
    font-size: 0.95rem;
    color: var(--text);
    margin: 0 0 0.5rem 0;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--border);
}
.modal-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 0.88rem;
    border-bottom: 1px dashed var(--border);
}
.modal-row:last-child { border-bottom: none; }
.modal-row .label { color: var(--text-dim); }
.modal-row .value { font-weight: 600; font-family: monospace; }
</style>
<script>
// Globální helpery (musí být v <head> aby fungovaly inline onclick)
window.toggleAll = function(check) {
    document.querySelectorAll('.plants-checkboxes input[type="checkbox"]').forEach(cb => cb.checked = check);
};
</script>
</head>
<body>

<header class="topbar">
    <h1>📊 Denní srovnání FVE</h1>
    <div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
        <a href="index.php" style="color:var(--text-dim)">← Dashboard</a>
        · <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?>
    </div>
</header>

<main>
<div class="help-box">
    <strong>📐 Režim porovnání:</strong>
    <?php if ($mode === 'denni'): ?>
        <span style="color:var(--accent);font-weight:600">🌤 Denní průměr</span>
    <?php else: ?>
        <span style="color:var(--accent);font-weight:600">📅 Měsíční průměr</span>
    <?php endif; ?>
    <br>
    <small style="opacity:0.9;display:block;margin-top:6px">
        <?php if ($mode === 'denni'): ?>
            Pro každý den se spočítá průměr productivity (kWh/kWp) ze všech vybraných FVE které měly v ten den data.
            <strong>Odchylka v buňce</strong> = jak FVE ten den vyrobila vůči tomu dennímu průměru.<br>
            <em>Výhoda: spravedlivé i v zataženém dni (všechny FVE měřené stejným počasím).</em>
        <?php else: ?>
            Spočítá se měsíční průměr (= průměr ze všech denních průměrů). Každá buňka pak ukazuje, jak FVE ten den vyrobila vůči <strong>celoměsíčnímu průměru</strong>.<br>
            <em>Výhoda: vidíš odchylky způsobené nejen výkonem FVE ale i počasím (ten den slunečno/zataženo).</em>
        <?php endif; ?>
        <br>
        <strong>Spodní řádek tabulky</strong> ukazuje měsíční productivity každé FVE — užitečné pro identifikaci dlouhodobých problémů (zaprášené panely, stín, závada).
    </small>
    <?php if ($mode === 'mesicni' && $monthlyBenchmark > 0): ?>
        <div style="margin-top:10px;padding:8px 12px;background:var(--surface-2);border-radius:4px;display:inline-block">
            <strong>📅 Měsíční benchmark:</strong>
            <span style="font-size:1.05rem;color:var(--accent);font-weight:700;font-family:monospace">
                <?= number_format($monthlyBenchmark, 3, ',', '') ?>
            </span>
            <span style="opacity:0.7">kWh/kWp</span>
            <small style="opacity:0.7;margin-left:8px">(průměr <?= count($dailyAverages) ?> denních průměrů)</small>
        </div>
    <?php elseif ($mode === 'denni' && !empty($dailyAverages)): ?>
        <div style="margin-top:10px;padding:8px 12px;background:var(--surface-2);border-radius:4px;display:inline-block;font-size:0.85rem">
            <strong>🌤 Denní benchmarky:</strong>
            min <span style="color:var(--accent);font-weight:600;font-family:monospace"><?= number_format(min($dailyAverages), 3, ',', '') ?></span>
            ·
            max <span style="color:var(--accent);font-weight:600;font-family:monospace"><?= number_format(max($dailyAverages), 3, ',', '') ?></span>
            ·
            Ø <span style="color:var(--accent);font-weight:600;font-family:monospace"><?= number_format(array_sum($dailyAverages)/count($dailyAverages), 3, ',', '') ?></span>
            <span style="opacity:0.7">kWh/kWp</span>
            <small style="opacity:0.6;display:block;margin-top:4px">Najetím myši na buňku uvidíš denní průměr pro konkrétní den.</small>
        </div>
    <?php endif; ?>
</div>

<form method="get" class="filter-bar">
    <div class="filter-row">
        <label>📅 Měsíc:</label>
        <select name="month">
            <?php foreach ($months as $i => $mname): ?>
                <option value="<?= $i + 1 ?>" <?= $month === ($i+1) ? 'selected' : '' ?>>
                    <?= ucfirst($mname) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="year" value="<?= $year ?>" min="2024" max="2030" style="width:80px">

        <label style="margin-left:1rem">📐 Režim:</label>
        <select name="mode">
            <option value="denni" <?= $mode === 'denni' ? 'selected' : '' ?>>Denní průměr (per den)</option>
            <option value="mesicni" <?= $mode === 'mesicni' ? 'selected' : '' ?>>Měsíční průměr (za celý měsíc)</option>
        </select>

        <label style="margin-left:1rem">⚠️ Stupeň 1:</label>
        <input type="number" name="t1" value="<?= $threshold1 ?>" min="0" max="200" style="width:60px"> %

        <label>⚠️ Stupeň 2:</label>
        <input type="number" name="t2" value="<?= $threshold2 ?>" min="0" max="200" style="width:60px"> %

        <button type="submit" class="btn-primary">Aplikovat</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 1])) ?>" class="btn-secondary">📥 Export CSV</a>
    </div>

    <div class="filter-row">
        <label>🏭 FVE:</label>
        <button type="button" class="btn-secondary" onclick="toggleAll(true)">Vybrat vše</button>
        <button type="button" class="btn-secondary" onclick="toggleAll(false)">Zrušit vše</button>
    </div>

    <div class="plants-checkboxes">
        <?php foreach ($allPlants as $p):
            $checked = in_array((int)$p['id'], $selectedIds, true);
        ?>
            <label>
                <input type="checkbox" name="plants[]" value="<?= $p['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                <?= htmlspecialchars($p['name']) ?>
                <small style="color:var(--text-dim)">(<?= number_format((float)$p['peak_power_kwp'], 0, ',', ' ') ?> kWp)</small>
            </label>
        <?php endforeach; ?>
    </div>
</form>

<div class="legend">
    <span class="legend-item"><span class="legend-swatch cell-ok"></span> Normál — odchylka do <?= $threshold1 ?>% od průměru</span>
    <span class="legend-item"><span class="legend-swatch cell-warn"></span> 1. stupeň — <?= $threshold1 ?>% až <?= $threshold2 ?>% od průměru</span>
    <span class="legend-item"><span class="legend-swatch cell-bad"></span> 2. stupeň — nad <?= $threshold2 ?>% od průměru</span>
    <span class="legend-item"><span class="legend-swatch cell-nodata" style="border:1px solid var(--border)"></span> Bez dat</span>
</div>

<?php if (empty($plants)): ?>
    <div class="help-box">⚠ Vyber alespoň jednu FVE pro zobrazení.</div>
<?php else: ?>
    <div class="table-scroll">
    <table class="comparison-table">
        <thead>
            <tr>
                <th class="day-col">Den</th>
                <?php foreach ($plants as $p): ?>
                    <th>
                        <?= htmlspecialchars($p['name']) ?>
                        <div style="font-size:0.7rem;font-weight:400;opacity:0.7;margin-top:2px">
                            <?= number_format((float)$p['peak_power_kwp'], 0, ',', ' ') ?> kWp
                        </div>
                        <div style="font-size:0.65rem;font-weight:400;opacity:0.55;margin-top:3px;font-style:italic">
                            kWh denně<br>± % od průměru
                        </div>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <tr>
                    <td class="day-cell"><?= sprintf('%02d.%02d.', $d, $month) ?></td>
                    <?php foreach ($plants as $p):
                        $pid = (int)$p['id'];
                        $cell = $grid[$d][$pid] ?? null;
                        if ($cell === null): ?>
                            <td class="cell-nodata">—</td>
                        <?php else:
                            // Vyber benchmark podle režimu
                            if ($mode === 'mesicni') {
                                $benchmark = $monthlyBenchmark;
                                $devPct = ($benchmark > 0) ? (($cell['productivity'] - $benchmark) / $benchmark) * 100 : 0;
                                $tipPrefix = 'Měsíční průměr: ';
                            } else {
                                $benchmark = $cell['avg'];
                                $devPct = $cell['deviation_pct'];
                                $tipPrefix = 'Denní průměr ('  . sprintf('%02d.%02d.', $d, $month) . '): ';
                            }
                            // Klasifikace podle prahů
                            $cls = 'cell-ok';
                            if (abs($devPct) > $threshold2) $cls = 'cell-bad';
                            elseif (abs($devPct) > $threshold1) $cls = 'cell-warn';
                        ?>
                            <td class="<?= $cls ?> clickable"
                                title="Klikni pro detail · <?= $tipPrefix ?><?= number_format($benchmark, 3) ?> kWh/kWp · Odchylka: <?= ($devPct >= 0 ? '+' : '') . number_format($devPct, 1) ?>%"
                                data-day="<?= $d ?>"
                                data-month="<?= $month ?>"
                                data-year="<?= $year ?>"
                                data-plant-id="<?= $pid ?>"
                                data-plant-name="<?= htmlspecialchars($p['name']) ?>"
                                data-plant-kwp="<?= $p['peak_power_kwp'] ?>"
                                data-kwh="<?= $cell['kwh'] ?>"
                                data-prod="<?= number_format($cell['productivity'], 4, '.', '') ?>"
                                data-day-avg="<?= number_format($cell['avg'], 4, '.', '') ?>"
                                data-month-avg="<?= number_format($monthlyBenchmark, 4, '.', '') ?>"
                                data-day-dev="<?= number_format($cell['deviation_pct'], 2, '.', '') ?>"
                                data-month-dev="<?= number_format(($monthlyBenchmark > 0) ? (($cell['productivity'] - $monthlyBenchmark) / $monthlyBenchmark) * 100 : 0, 2, '.', '') ?>"
                                onclick="showDayDetail(this)">
                                <span class="cell-kwh"><?= number_format($cell['kwh'], 1, ',', ' ') ?> <span class="unit-suffix">kWh</span></span>
                                <span class="cell-pct">
                                    <?= ($devPct >= 0 ? '+' : '') . number_format($devPct, 1, ',', '') ?> %
                                </span>
                            </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="day-cell">Σ Celkem</td>
                <?php foreach ($plants as $p): ?>
                    <td>
                        <span class="cell-kwh"><?= number_format($plantTotals[(int)$p['id']], 0, ',', ' ') ?> <span class="unit-suffix">kWh</span></span>
                        <span class="cell-pct" style="opacity:0.6">měsíční Σ</span>
                    </td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td class="day-cell" title="Průměrná specifická výroba kWh na 1 kWp instalovaného výkonu">
                    Ø productivity
                    <div style="font-size:0.7rem;font-weight:400;opacity:0.7">kWh/kWp</div>
                </td>
                <?php
                // Měsíční průměr = průměr denních průměrů (= správný benchmark pro počasí)
                $monthlyAvg = !empty($dailyAverages) ? array_sum($dailyAverages) / count($dailyAverages) : 0;

                foreach ($plants as $p):
                    $stats = $plantProductivity[(int)$p['id']];
                    $prod = $stats['days'] > 0 ? $stats['sum'] / $stats['days'] : 0;
                    $devPct = $monthlyAvg > 0 ? (($prod - $monthlyAvg) / $monthlyAvg) * 100 : 0;
                    $cls = 'cell-ok';
                    if (abs($devPct) > $threshold2) $cls = 'cell-bad';
                    elseif (abs($devPct) > $threshold1) $cls = 'cell-warn';
                    $tip = sprintf(
                        'Tato FVE: %s kWh/kWp (%d dn\xC5\xAF) · M\xC4\x9Bs\xC3\xADc\xC5\x88n\xC3\xAD pr\xC5\xAFm\xC4\x9Br: %s kWh/kWp · Odchylka: %s%%',
                        number_format($prod, 3),
                        $stats['days'],
                        number_format($monthlyAvg, 3),
                        ($devPct >= 0 ? '+' : '') . number_format($devPct, 1)
                    );
                ?>
                    <td class="<?= $cls ?>" title="<?= htmlspecialchars($tip) ?>">
                        <span class="cell-kwh"><?= number_format($prod, 3, ',', '') ?> <span class="unit-suffix">kWh/kWp</span></span>
                        <span class="cell-pct">
                            <?= ($devPct >= 0 ? '+' : '') . number_format($devPct, 1, ',', '') ?> % od Ø
                        </span>
                        <?php if ($stats['days'] < $daysInMonth * 0.8): ?>
                            <div style="font-size:0.65rem;opacity:0.6;margin-top:2px;font-style:italic">
                                jen <?= $stats['days'] ?> z <?= $daysInMonth ?> dní
                            </div>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>
    </div>
<?php endif; ?>

<!-- Modal pro detail dne -->
<div id="day-modal" class="day-modal" onclick="closeDayModal(event)">
    <div class="day-modal-content" onclick="event.stopPropagation()">
        <div class="day-modal-header">
            <h2 id="modal-title">Detail dne</h2>
            <button class="day-modal-close" onclick="closeDayModal()">×</button>
        </div>
        <div class="day-modal-body" id="modal-body"></div>
    </div>
</div>

<script>
window.showDayDetail = function(td) {
    const data = td.dataset;
    const day = parseInt(data.day, 10);
    const month = parseInt(data.month, 10);
    const year = parseInt(data.year, 10);
    const plantName = data.plantName;
    const plantKwp = parseFloat(data.plantKwp);
    const kwh = parseFloat(data.kwh);
    const prod = parseFloat(data.prod);
    const dayAvg = parseFloat(data.dayAvg);
    const monthAvg = parseFloat(data.monthAvg);
    const dayDev = parseFloat(data.dayDev);
    const monthDev = parseFloat(data.monthDev);

    const months = ['leden','únor','březen','duben','květen','červen','červenec','srpen','září','říjen','listopad','prosinec'];
    document.getElementById('modal-title').textContent = `${plantName} · ${day}. ${months[month-1]} ${year}`;

    const classifyDev = (pct) => {
        const abs = Math.abs(pct);
        if (abs > 50) return 'bad';
        if (abs > 25) return 'warn';
        return 'good';
    };
    const fmt = (n, dec=2) => n.toLocaleString('cs-CZ', {minimumFractionDigits: dec, maximumFractionDigits: dec});
    const sign = (n) => n >= 0 ? '+' : '';

    document.getElementById('modal-body').innerHTML = `
        <div class="modal-stats">
            <div class="modal-stat">
                <div class="modal-stat-label">Denní výroba</div>
                <div class="modal-stat-value accent">${fmt(kwh, 1)}<span class="modal-stat-unit">kWh</span></div>
            </div>
            <div class="modal-stat">
                <div class="modal-stat-label">Productivity</div>
                <div class="modal-stat-value">${fmt(prod, 3)}<span class="modal-stat-unit">kWh/kWp</span></div>
            </div>
            <div class="modal-stat">
                <div class="modal-stat-label">Instalovaný výkon</div>
                <div class="modal-stat-value">${fmt(plantKwp, 1)}<span class="modal-stat-unit">kWp</span></div>
            </div>
            <div class="modal-stat">
                <div class="modal-stat-label">Hod. výroba (Ø)</div>
                <div class="modal-stat-value">${fmt(prod / 24 * 1000, 0)}<span class="modal-stat-unit">Wh/kWp/h</span></div>
            </div>
        </div>

        <div class="modal-section">
            <h3>🌤 Porovnání s denním průměrem</h3>
            <div class="modal-row"><span class="label">Denní průměr (vybraných FVE)</span><span class="value">${fmt(dayAvg, 3)} kWh/kWp</span></div>
            <div class="modal-row"><span class="label">Tato FVE</span><span class="value">${fmt(prod, 3)} kWh/kWp</span></div>
            <div class="modal-row"><span class="label">Odchylka</span><span class="value" style="color: var(--${classifyDev(dayDev)})">${sign(dayDev)}${fmt(dayDev, 1)} %</span></div>
        </div>

        <div class="modal-section">
            <h3>📅 Porovnání s měsíčním průměrem</h3>
            <div class="modal-row"><span class="label">Měsíční průměr</span><span class="value">${fmt(monthAvg, 3)} kWh/kWp</span></div>
            <div class="modal-row"><span class="label">Tato FVE (tento den)</span><span class="value">${fmt(prod, 3)} kWh/kWp</span></div>
            <div class="modal-row"><span class="label">Odchylka</span><span class="value" style="color: var(--${classifyDev(monthDev)})">${sign(monthDev)}${fmt(monthDev, 1)} %</span></div>
        </div>

        <div class="modal-section" style="opacity:0.6;font-size:0.82rem;font-style:italic">
            💡 Pozn.: 15-minutový graf výkonu bude v jedné z dalších verzí.
        </div>
    `;
    document.getElementById('day-modal').classList.add('open');
};

window.closeDayModal = function(event) {
    if (event && event.target.id !== 'day-modal' && event.target.className !== 'day-modal-close') return;
    document.getElementById('day-modal').classList.remove('open');
};

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.getElementById('day-modal')?.classList.remove('open');
});
</script>
</main>
</body>
</html>
