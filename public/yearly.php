<?php
/**
 * Roční přehled — pivot tabulka FVE × měsíc, plnění PVGIS predikce.
 *
 * GET:
 *   year=YYYY       (default: aktuální rok)
 *   plants[]=ID     (default: všechny aktivní)
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Auth;
use FveMonitor\Lib\Acl;

Auth::start();
Acl::requireAccess('yearly');
$user = Auth::currentUser();

$now  = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));
$year = (int)($_GET['year'] ?? $now->format('Y'));
$year = max(2020, min(2035, $year));

// ─── FVE: pokud nic, vyber všechny ───
$allPlants = Database::all(
    "SELECT id, code, name, peak_power_kwp, evid_number
       FROM plants
      WHERE is_active = 1
      ORDER BY COALESCE(evid_number, 99), name"
);

$rawSelected = $_GET['plants'] ?? null;
if ($rawSelected === null) {
    $selectedIds = array_map(fn($p) => (int)$p['id'], $allPlants);
} else {
    $selectedIds = array_map('intval', (array)$rawSelected);
}
if (empty($selectedIds)) {
    $selectedIds = array_map(fn($p) => (int)$p['id'], $allPlants);
}

$plants = array_values(array_filter(
    $allPlants,
    fn($p) => in_array((int)$p['id'], $selectedIds, true)
));

// Roky pro dropdown
$years = [];
foreach (Database::all("SELECT DISTINCT YEAR(day) AS y FROM production_daily ORDER BY y DESC") as $y) {
    $years[] = (int)$y['y'];
}
if (!in_array((int)date('Y'), $years, true)) $years[] = (int)date('Y');
sort($years);

// ─── Reálná data (per_plant per_month kWh) ───
$realData = []; // [plant_id][month] => kwh
foreach (Database::all(
    "SELECT plant_id, MONTH(day) AS m, SUM(energy_kwh) AS kwh
       FROM production_daily
      WHERE YEAR(day) = ?
      GROUP BY plant_id, MONTH(day)",
    [$year]
) as $r) {
    $realData[(int)$r['plant_id']][(int)$r['m']] = (float)$r['kwh'];
}

// ─── PVGIS predikce (per_plant per_month kWh) ───
$pvgisData = [];
foreach (Database::all(
    "SELECT plant_id, month, SUM(e_m_kwh) AS kwh
       FROM pvgis_monthly
      GROUP BY plant_id, month"
) as $p) {
    $pvgisData[(int)$p['plant_id']][(int)$p['month']] = (float)$p['kwh'];
}

// ─── Heatmap: barva podle plnění % (0..120) ───
function heatColor(?float $pct): string {
    if ($pct === null) return 'background:var(--surface-2);color:var(--text-dim)';
    // Clamp pro výpočet barvy 0..120, ale text ukáže skutečnou hodnotu
    $clamped = max(0.0, min(120.0, $pct));
    // hue: 0 = červená, 120 = zelená
    $hue = $clamped / 120.0 * 120.0;
    $sat = 55;
    // Tmavá tabulka — světlost upravíme tak, aby bylo čitelné
    $light = 35 + ($clamped / 120.0) * 10; // 35..45
    return "background: hsl({$hue}, {$sat}%, {$light}%); color:#fff;";
}

function fmtKwh(float $v): string {
    if ($v >= 1000) return number_format($v / 1000, 1, ',', ' ') . ' MWh';
    return number_format($v, 0, ',', ' ') . ' kWh';
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

// Sumy per FVE za rok
$rowTotals = []; // [plant_id] => ['real'=>..., 'pvgis'=>...]
$colTotals = array_fill(1, 12, ['real' => 0.0, 'pvgis' => 0.0]);
$grandTotal = ['real' => 0.0, 'pvgis' => 0.0];

foreach ($plants as $p) {
    $pid = (int)$p['id'];
    $sumR = $sumP = 0;
    for ($m = 1; $m <= 12; $m++) {
        $r = $realData[$pid][$m]  ?? 0;
        $g = $pvgisData[$pid][$m] ?? 0;
        $sumR += $r;
        $sumP += $g;
        $colTotals[$m]['real']  += $r;
        $colTotals[$m]['pvgis'] += $g;
    }
    $rowTotals[$pid] = ['real' => $sumR, 'pvgis' => $sumP];
    $grandTotal['real']  += $sumR;
    $grandTotal['pvgis'] += $sumP;
}

$monthsShort = ['','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
$monthsLong  = ['','leden','únor','březen','duben','květen','červen','červenec','srpen','září','říjen','listopad','prosinec'];

// Současný měsíc - pro zvýraznění "dosud"
$currentYear  = (int)$now->format('Y');
$currentMonth = (int)$now->format('n');

// ───────────────────────────────────────────
// EXPORT (CSV / XLSX) — respektuje filtry
// ───────────────────────────────────────────
$exportFmt = $_GET['export'] ?? null;
if ($exportFmt === 'csv' || $exportFmt === 'xlsx') {

    // Build pole pro export: 2-řádkový header + 1 řádek per FVE + součet
    $h1 = ['FVE'];
    $h2 = [''];
    for ($m = 1; $m <= 12; $m++) {
        $h1[] = ucfirst($monthsLong[$m]); $h1[] = ''; $h1[] = '';
        $h2[] = 'Real (kWh)'; $h2[] = 'PVGIS (kWh)'; $h2[] = 'Plnění %';
    }
    $h1[] = 'Σ rok'; $h1[] = ''; $h1[] = '';
    $h2[] = 'Real (kWh)'; $h2[] = 'PVGIS (kWh)'; $h2[] = 'Plnění %';

    $rows = [$h1, $h2];

    foreach ($plants as $p) {
        $pid = (int)$p['id'];
        $row = [$p['name']];
        for ($m = 1; $m <= 12; $m++) {
            $r = $realData[$pid][$m]  ?? null;
            $g = $pvgisData[$pid][$m] ?? null;
            $pct = ($r !== null && $g !== null && $g > 0) ? round(($r / $g) * 100, 1) : null;
            $row[] = $r !== null ? round($r, 1) : '';
            $row[] = $g !== null ? round($g, 1) : '';
            $row[] = $pct !== null ? $pct : '';
        }
        $rt = $rowTotals[$pid];
        $rowPct = $rt['pvgis'] > 0 ? round(($rt['real'] / $rt['pvgis']) * 100, 1) : '';
        $row[] = round($rt['real'], 1);
        $row[] = round($rt['pvgis'], 1);
        $row[] = $rowPct;
        $rows[] = $row;
    }

    // Závěrečný součet
    $sumRow = ['Σ vše (' . count($plants) . ')'];
    for ($m = 1; $m <= 12; $m++) {
        $cr = $colTotals[$m]['real'];
        $cp = $colTotals[$m]['pvgis'];
        $cPct = $cp > 0 ? round(($cr / $cp) * 100, 1) : '';
        $sumRow[] = round($cr, 1);
        $sumRow[] = round($cp, 1);
        $sumRow[] = $cPct;
    }
    $gPct = $grandTotal['pvgis'] > 0 ? round(($grandTotal['real'] / $grandTotal['pvgis']) * 100, 1) : '';
    $sumRow[] = round($grandTotal['real'], 1);
    $sumRow[] = round($grandTotal['pvgis'], 1);
    $sumRow[] = $gPct;
    $rows[] = $sumRow;

    $fnameBase = "yearly_fve_{$year}";

    if ($exportFmt === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$fnameBase}.csv\"");
        $out = fopen('php://output', 'w');
        // UTF-8 BOM pro Excel
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($out, $r, ';');
        }
        fclose($out);
        exit;
    }

    // XLSX export
    require_once __DIR__ . '/../lib/SimpleXLSXGen.php';
    $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($rows);
    $xlsx->setDefaultFont('Calibri', 11);
    // Title sheet
    $xlsx->setTitle("Roční přehled FVE {$year}");
    $xlsx->downloadAs("{$fnameBase}.xlsx");
    exit;
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<?php
$pageTitle = '📆 Roční přehled FVE';
$includeChart = false;
require __DIR__ . '/_app_head.php';
?>
<style>
.filter-bar { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 1rem; }
.filter-row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-start; margin-bottom: 0.75rem; }
.filter-row:last-child { margin-bottom: 0; }
.filter-bar select { padding: 6px 10px; background: var(--surface-2); color: var(--text); border: 1px solid var(--border); border-radius: 4px; }
.plants-checkboxes { display: flex; flex-wrap: wrap; gap: 6px; }
.plants-checkboxes label { display: flex; align-items: center; gap: 6px; padding: 6px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
.plants-checkboxes label:hover { border-color: var(--accent); }
.plants-checkboxes input[type="checkbox"] { margin: 0; cursor: pointer; }
.shortcut-btns { display: flex; gap: 6px; align-items: center; }
.btn-mini { padding: 4px 10px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; color: var(--text); cursor: pointer; font-size: 0.8rem; text-decoration: none; }
.btn-mini:hover { border-color: var(--accent); }

.yearly-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.yearly-table th { padding: 8px 4px; background: var(--surface); border: 1px solid var(--border); text-align: center; font-weight: 600; position: sticky; top: 0; z-index: 2; }
.yearly-table th.plant-col { text-align: left; padding-left: 10px; min-width: 180px; }
.yearly-table th.month-current { background: var(--surface-2); color: var(--accent); }
.yearly-table td { padding: 6px 4px; border: 1px solid var(--border); text-align: center; vertical-align: middle; }
.yearly-table td.plant-col { text-align: left; padding: 8px 10px; background: var(--surface); position: sticky; left: 0; z-index: 1; }
.yearly-table tr:hover td.plant-col { background: var(--surface-2); }
.yearly-table td.heat { line-height: 1.2; font-variant-numeric: tabular-nums; }
.yearly-table td.heat .real { font-weight: 700; font-size: 0.92rem; }
.yearly-table td.heat .pvgis { display: block; opacity: 0.85; font-size: 0.72rem; }
.yearly-table td.heat .pct { display: block; font-size: 0.7rem; font-weight: 600; opacity: 0.95; margin-top: 2px; }
.yearly-table td.empty { color: var(--text-dim); font-size: 0.75rem; }
.yearly-table tr.total-row td { background: var(--surface); font-weight: 700; border-top: 2px solid var(--accent); }
.yearly-table td.total-col { background: var(--surface); font-weight: 600; }
.plant-name { font-weight: 600; }
.plant-meta { font-size: 0.72rem; color: var(--text-dim); }

.legend { display: flex; gap: 8px; align-items: center; font-size: 0.8rem; color: var(--text-dim); margin-bottom: 1rem; }
.legend-bar { display: inline-block; width: 240px; height: 14px; border-radius: 3px; background: linear-gradient(to right, hsl(0,55%,35%), hsl(60,55%,40%), hsl(120,55%,45%)); }
</style>
</head>
<body>
<?php
$pageHeading = '📆 Roční přehled FVE';
$activePage  = 'yearly';
require __DIR__ . '/_topbar.php';
?>

<main>
    <form method="get" class="filter-bar">
        <div class="filter-row">
            <label>Rok:
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="shortcut-btns">
                <a href="?year=<?= $year ?>" class="btn-mini">✓ Vybrat vše</a>
                <a href="?year=<?= $year ?>&plants[]=0" class="btn-mini">✗ Zrušit vše</a>
            </div>
            <div class="shortcut-btns" style="margin-left:auto">
                <?php
                $exportQs = http_build_query(array_filter([
                    'year'   => $year,
                    'plants' => $selectedIds === array_map(fn($p)=>(int)$p['id'], $allPlants) ? null : $selectedIds,
                ]));
                ?>
                <a href="?<?= $exportQs ?>&export=csv"  class="btn-mini" title="Stáhnout CSV">📥 CSV</a>
                <a href="?<?= $exportQs ?>&export=xlsx" class="btn-mini" title="Stáhnout Excel">📊 XLSX</a>
            </div>
        </div>
        <div class="filter-row">
            <div class="plants-checkboxes">
            <?php foreach ($allPlants as $p):
                $checked = in_array((int)$p['id'], $selectedIds, true);
            ?>
                <label>
                    <input type="checkbox" name="plants[]" value="<?= (int)$p['id'] ?>" <?= $checked ? 'checked' : '' ?> onchange="this.form.submit()">
                    <?= h($p['name']) ?>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
    </form>

    <div class="legend">
        Plnění PVGIS:
        <span>0%</span>
        <span class="legend-bar"></span>
        <span>120%</span>
        <span style="margin-left:1rem">Buňka: <strong>real</strong> · pvgis · %</span>
    </div>

    <div style="overflow-x:auto;">
    <table class="yearly-table">
        <thead>
            <tr>
                <th class="plant-col">FVE</th>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <th class="<?= ($year === $currentYear && $m === $currentMonth) ? 'month-current' : '' ?>"
                        title="<?= h($monthsLong[$m]) ?>">
                        <?= $monthsShort[$m] ?>
                    </th>
                <?php endfor; ?>
                <th>Σ rok</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($plants as $p):
            $pid = (int)$p['id'];
        ?>
            <tr>
                <td class="plant-col">
                    <span class="plant-name"><?= h($p['name']) ?></span>
                    <?php if (!empty($p['peak_power_kwp'])): ?>
                        <div class="plant-meta"><?= number_format((float)$p['peak_power_kwp'], 1, ',', ' ') ?> kWp</div>
                    <?php endif; ?>
                </td>
                <?php for ($m = 1; $m <= 12; $m++):
                    $r = $realData[$pid][$m]  ?? null;
                    $g = $pvgisData[$pid][$m] ?? null;
                    $hasReal = $r !== null && $r > 0;
                    $hasPvg  = $g !== null && $g > 0;
                    // Pokud měsíc je v budoucnosti (a rok = current), nepočítej ratio
                    $isFuture = ($year === $currentYear && $m > $currentMonth);
                    $pct = ($hasReal && $hasPvg) ? ($r / $g) * 100 : null;
                ?>
                    <?php if (!$hasReal && !$hasPvg): ?>
                        <td class="empty">—</td>
                    <?php elseif ($isFuture && !$hasReal): ?>
                        <td class="empty" title="<?= number_format((float)$g, 0, ',', ' ') ?> kWh PVGIS">
                            <span style="opacity:0.6"><?= number_format((float)$g, 0, ',', ' ') ?></span>
                            <div style="font-size:0.65rem;opacity:0.5">budoucnost</div>
                        </td>
                    <?php else: ?>
                        <td class="heat" style="<?= heatColor($pct) ?>"
                            title="<?= h($monthsLong[$m]) ?> <?= $year ?>: <?= number_format((float)($r ?? 0), 0, ',', ' ') ?> / <?= number_format((float)($g ?? 0), 0, ',', ' ') ?> kWh">
                            <span class="real"><?= number_format((float)($r ?? 0), 0, ',', ' ') ?></span>
                            <span class="pvgis"><?= number_format((float)($g ?? 0), 0, ',', ' ') ?></span>
                            <span class="pct"><?= $pct !== null ? number_format($pct, 0) . '%' : '—' ?></span>
                        </td>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php
                $rt = $rowTotals[$pid];
                $rowPct = $rt['pvgis'] > 0 ? ($rt['real'] / $rt['pvgis']) * 100 : null;
                ?>
                <td class="total-col" style="<?= heatColor($rowPct) ?>">
                    <span class="real"><?= number_format($rt['real'], 0, ',', ' ') ?></span>
                    <span class="pvgis" style="display:block;font-size:0.72rem;opacity:0.85"><?= number_format($rt['pvgis'], 0, ',', ' ') ?></span>
                    <span class="pct" style="display:block;font-size:0.7rem"><?= $rowPct !== null ? number_format($rowPct, 0) . '%' : '—' ?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td class="plant-col">Σ vše ({{count}})</td>
                <?php for ($m = 1; $m <= 12; $m++):
                    $cr = $colTotals[$m]['real'];
                    $cp = $colTotals[$m]['pvgis'];
                    $cPct = $cp > 0 ? ($cr / $cp) * 100 : null;
                ?>
                    <td>
                        <span class="real"><?= number_format($cr, 0, ',', ' ') ?></span>
                        <span class="pvgis" style="display:block;font-size:0.72rem;opacity:0.7"><?= number_format($cp, 0, ',', ' ') ?></span>
                        <span class="pct" style="display:block;font-size:0.7rem"><?= $cPct !== null ? number_format($cPct, 0) . '%' : '—' ?></span>
                    </td>
                <?php endfor;
                $gPct = $grandTotal['pvgis'] > 0 ? ($grandTotal['real'] / $grandTotal['pvgis']) * 100 : null;
                ?>
                <td>
                    <span class="real"><?= number_format($grandTotal['real'], 0, ',', ' ') ?></span>
                    <span class="pvgis" style="display:block;font-size:0.72rem;opacity:0.7"><?= number_format($grandTotal['pvgis'], 0, ',', ' ') ?></span>
                    <span class="pct" style="display:block;font-size:0.7rem"><?= $gPct !== null ? number_format($gPct, 0) . '%' : '—' ?></span>
                </td>
            </tr>
        </tfoot>
    </table>
    </div>
</main>

<script>
// Nahraď {{count}} skutečným počtem FVE
document.querySelectorAll('.total-row .plant-col').forEach(el => {
    el.innerHTML = el.innerHTML.replace('{{count}}', '<?= count($plants) ?>');
});
</script>
</body>
</html>
