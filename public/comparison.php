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
foreach ($plants as $p) $plantTotals[(int)$p['id']] = 0.0;

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
    <strong>📊 Co tabulka ukazuje:</strong> Pro každý den v měsíci a pro každou FVE: <strong>denní výrobu v kWh</strong> a <strong>odchylku od denního průměru</strong> v %.<br>
    <small style="opacity:0.85;display:block;margin-top:6px">
        <strong>Jak se počítá odchylka:</strong> Pro každý den se spočítá <strong>průměrná produktivita</strong> (kWh/kWp) ze všech vybraných FVE.
        Pak pro každou FVE: <code>(její kWh/kWp − průměr) ÷ průměr × 100 %</code>.<br>
        <strong>Příklad:</strong> Když průměr je <code>0.80 kWh/kWp</code> a Plzeň dosáhla <code>0.86 kWh/kWp</code>, její odchylka je <code>+7,5 %</code> (vyrobila o 7,5 % víc než ostatní FVE v ten den).<br>
        Příklad buňky: <span style="background:rgba(63,185,80,0.30);padding:2px 8px;border-radius:3px"><strong>345 kWh</strong> · <small>+8 % oproti průměru</small></span>
    </small>
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
                            $cls = 'cell-' . $cell['status'];
                        ?>
                            <td class="<?= $cls ?>" title="Tato FVE: <?= number_format($cell['productivity'], 3) ?> kWh/kWp · Průměr ostatních: <?= number_format($cell['avg'], 3) ?> kWh/kWp · Odchylka: <?= ($cell['deviation_pct'] >= 0 ? '+' : '') . number_format($cell['deviation_pct'], 1) ?>%">
                                <span class="cell-kwh"><?= number_format($cell['kwh'], 1, ',', ' ') ?> <span class="unit-suffix">kWh</span></span>
                                <span class="cell-pct">
                                    <?= ($cell['deviation_pct'] >= 0 ? '+' : '') . number_format($cell['deviation_pct'], 1, ',', '') ?> %
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
        </tfoot>
    </table>
    </div>
<?php endif; ?>
</main>
</body>
</html>
