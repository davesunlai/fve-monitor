<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Auth;

$user = Auth::currentUser();

// Vybrané období (default: předchozí měsíc — typický OTE workflow)
$now    = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));
$prev   = $now->modify('first day of last month');
$year   = (int)($_GET['year']  ?? $prev->format('Y'));
$month  = (int)($_GET['month'] ?? $prev->format('n'));
$format = $_GET['export'] ?? null;

$year  = max(2024, min(2030, $year));
$month = max(1, min(12, $month));

$periodFrom = sprintf('%04d-%02d-01', $year, $month);
$periodTo   = (new DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');
$daysInMonth = (int)(new DateTimeImmutable($periodFrom))->format('t');

// Načti FVE + měsíční data
$plants = Database::all(
    "SELECT p.*,
            COALESCE(SUM(d.energy_kwh), 0) AS month_kwh,
            COALESCE(MAX(d.peak_kw), 0) AS month_peak_kw,
            COUNT(d.day) AS days_with_data
     FROM plants p
     LEFT JOIN production_daily d ON d.plant_id = p.id
        AND d.day BETWEEN ? AND ?
     WHERE p.is_active = 1
     GROUP BY p.id
     ORDER BY p.name",
    [$periodFrom, $periodTo]
);

// Sestavení rows pro výkaz
$rows = [];
foreach ($plants as $p) {
    $gcr1 = (float) $p['peak_power_kwp'];   // instalovaný výkon kWp
    $gcr2 = round((float) $p['month_kwh'], 2); // svorková výroba kWh
    $gcr3 = 0.0;  // technologická vlastní spotřeba (FVE typicky 0)
    $res18 = $p['supported'] ? max(0, $gcr2 - $gcr3) : 0;

    $rows[] = [
        'id'                => (int) $p['id'],
        'code'              => $p['code'],
        'name'              => $p['name'],
        'ote_id'            => $p['ote_id'],
        'ote_vyrobna_id'    => $p['ote_vyrobna_id'],
        'ean_code'          => $p['ean_code'],
        'license_number'    => $p['license_number'],
        'ico'               => $p['ico'],
        'operator_name'     => $p['operator_name'],
        'supported'         => (int) $p['supported'],
        'support_type'      => $p['support_type'],
        'commissioning_date' => $p['commissioning_date'],
        'gcr1_installed_kwp' => $gcr1,
        'gcr2_production_kwh' => $gcr2,
        'gcr3_aux_kwh'      => $gcr3,
        'res18_supported_kwh' => $res18,
        'days_with_data'    => (int) $p['days_with_data'],
        'completeness'      => $daysInMonth > 0 ? round(100 * $p['days_with_data'] / $daysInMonth) : 0,
    ];
}

// ─────────────────────────────────────────────
// EXPORT CSV
// ─────────────────────────────────────────────
if ($format === 'csv') {
    $filename = sprintf('OTE_vykaz_%04d_%02d.csv', $year, $month);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOM pro Excel UTF-8
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    // Hlavička
    fputcsv($out, [
        'Kod', 'Nazev FVE', 'ID zdroje OTE', 'ID vyrobny', 'EAN OPM',
        'Licence ERU', 'ICO', 'Provozovatel',
        'Mesic', 'Rok',
        'GCR_1 instalovany vykon (kWp)',
        'GCR_2 svorkova vyroba (kWh)',
        'GCR_3 technol. spotreba (kWh)',
        'RES_18 podporovane (kWh)',
        'Dni s daty', 'Uplnost (%)',
    ], ';');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['code'], $r['name'], $r['ote_id'], $r['ote_vyrobna_id'], $r['ean_code'],
            $r['license_number'], $r['ico'], $r['operator_name'],
            sprintf('%02d', $month), $year,
            number_format($r['gcr1_installed_kwp'], 2, ',', ''),
            number_format($r['gcr2_production_kwh'], 2, ',', ''),
            number_format($r['gcr3_aux_kwh'], 2, ',', ''),
            number_format($r['res18_supported_kwh'], 2, ',', ''),
            $r['days_with_data'], $r['completeness'] . '%',
        ], ';');
    }
    fclose($out);
    exit;
}

// ─────────────────────────────────────────────
// EXPORT XML (zjednodušený OTE-friendly tvar)
// ─────────────────────────────────────────────
if ($format === 'xml') {
    $filename = sprintf('OTE_vykaz_%04d_%02d.xml', $year, $month);
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><MesicniVykazVyroby/>');
    $xml->addAttribute('Mesic', sprintf('%02d', $month));
    $xml->addAttribute('Rok', (string) $year);
    $xml->addAttribute('Generovano', $now->format('c'));

    foreach ($rows as $r) {
        $z = $xml->addChild('Zdroj');
        $z->addAttribute('Kod', $r['code']);

        $z->addChild('IdZdrojeOTE', htmlspecialchars((string) $r['ote_id']));
        $z->addChild('IdVyrobny',   htmlspecialchars((string) $r['ote_vyrobna_id']));
        $z->addChild('EAN_OPM',     htmlspecialchars((string) $r['ean_code']));
        $z->addChild('Licence',     htmlspecialchars((string) $r['license_number']));
        $z->addChild('ICO',         htmlspecialchars((string) $r['ico']));
        $z->addChild('Provozovatel', htmlspecialchars((string) $r['operator_name']));

        $vykaz = $z->addChild('Vykaz');
        $vykaz->addChild('GCR_1', number_format($r['gcr1_installed_kwp'], 2, '.', ''));
        $vykaz->addChild('GCR_2', number_format($r['gcr2_production_kwh'], 2, '.', ''));
        $vykaz->addChild('GCR_3', number_format($r['gcr3_aux_kwh'], 2, '.', ''));
        if ($r['supported']) {
            $vykaz->addChild('RES_18', number_format($r['res18_supported_kwh'], 2, '.', ''));
        }

        $z->addChild('DniSDaty',  (string) $r['days_with_data']);
        $z->addChild('Uplnost',   $r['completeness'] . '%');
    }

    // Pretty print
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    echo $dom->saveXML();
    exit;
}

// ─────────────────────────────────────────────
// EXPORT XML RESDATA (OTE POZE formát pro upload)
// ─────────────────────────────────────────────
if ($format === 'xml_resdata') {
    $msgCodeForFile = $_GET['msg_code'] ?? 'PD1';
    $filename = sprintf('RESDATA_%s_%04d_%02d.xml', $msgCodeForFile, $year, $month);
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $lastDay    = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month');
    $dateFrom   = sprintf('%04d-%02d-01', $year, $month);
    $dateTo     = $lastDay->format('Y-m-d');
    $xmlNow     = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s');
    $msgId      = 'FVE-' . $year . '-' . sprintf('%02d', $month) . '-' . substr(md5($xmlNow), 0, 8);

    $senderEan  = '8591824648933';  // Monkstone Solar s.r.o. EAN
    $receiverEan = '8591824000007'; // OTE EAN

    // Filtruj jen zaškrtnuté FVE
    $includePlants = isset($_GET['plants']) ? array_map('intval', (array)$_GET['plants']) : null;

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<RESDATA xmlns="http://www.ote-cr.cz/schema/oze/data"' . "\n";
    $xml .= '  id="' . $msgId . '"' . "\n";
    $msgCode = $_GET['msg_code'] ?? 'PD1';
    if (!in_array($msgCode, ['PD1', 'PDR'], true)) $msgCode = 'PD1';
    $xml .= '  message-code="' . $msgCode . '"' . "\n";
    $xml .= '  date-time="' . $xmlNow . '"' . "\n";
    $xml .= '  dtd-version="1"' . "\n";
    $xml .= '  dtd-release="1"' . "\n";
    $xml .= '  answer-required="0"' . "\n";
    $xml .= '  language="CS">' . "\n";
    $xml .= '  <SenderIdentification id="' . $senderEan . '" coding-scheme="14"/>' . "\n";
    $xml .= '  <ReceiverIdentification id="' . $receiverEan . '" coding-scheme="14"/>' . "\n";

    foreach ($rows as $r) {
        if (empty($r['ote_id']) || empty($r['ote_vyrobna_id'])) continue;
        if ($includePlants !== null && !in_array((int)$r['id'], $includePlants)) continue;

        $gcr1 = round($r['gcr1_installed_kwp'] / 1000, 5); // kWp → MW
        $gcr2 = round($r['gcr2_production_kwh'] / 1000, 5); // kWh → MWh

        $xml .= '  <Location' . "\n";
        $xml .= '    source-id="' . htmlspecialchars($r['ote_id']) . '"' . "\n";
        $xml .= '    opm-id="' . htmlspecialchars($r['ote_vyrobna_id']) . '"' . "\n";
        $xml .= '    date-from="' . $dateFrom . '"' . "\n";
        $xml .= '    date-to="' . $dateTo . '">' . "\n";
        $xml .= '    <Data value-type="GCR_1" unit="MW" value="' . number_format($gcr1, 5, '.', '') . '"/>' . "\n";
        $xml .= '    <Data value-type="GCR_2" unit="MWH" value="' . number_format($gcr2, 5, '.', '') . '"/>' . "\n";
        $xml .= '    <Data value-type="GCR_3" unit="MWH" value="0.00000"/>' . "\n";
        $xml .= '    <Data value-type="GCR_13D" unit="MWH" value="0.00000"/>' . "\n";
        $xml .= '  </Location>' . "\n";
    }

    $xml .= '</RESDATA>' . "\n";
    echo $xml;
    exit;
}

// ─────────────────────────────────────────────
// HTML zobrazení
// ─────────────────────────────────────────────
$totalProduction = array_sum(array_column($rows, 'gcr2_production_kwh'));
$totalSupported  = array_sum(array_column($rows, 'res18_supported_kwh'));
$avgCompleteness = $rows ? round(array_sum(array_column($rows, 'completeness')) / count($rows)) : 0;

$months = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen',
           'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OTE měsíční výkaz — FVE Monitor Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .period-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .period-bar select, .period-bar input[type="number"] {
            padding: 8px 10px;
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 4px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 1rem;
        }
        .stat-num {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--accent);
            display: block;
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .ote-report-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .ote-report-table th, .ote-report-table td {
            padding: 8px 10px;
            text-align: right;
            border-bottom: 1px solid var(--border);
        }
        .ote-report-table th {
            background: var(--surface-2);
            color: var(--text-dim);
            font-size: 0.72rem;
            text-transform: uppercase;
            text-align: center;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .ote-report-table td.text-left, .ote-report-table th.text-left {
            text-align: left;
        }
        .ote-report-table td:nth-child(1) {
            font-weight: 600;
            color: var(--accent);
        }
        .meta-row {
            font-size: 0.75rem;
            color: var(--text-dim);
            margin-top: 2px;
        }
        .completeness-bar {
            display: inline-block;
            width: 50px;
            height: 6px;
            background: var(--surface-2);
            border-radius: 3px;
            overflow: hidden;
            vertical-align: middle;
            margin-right: 4px;
        }
        .completeness-bar > span {
            display: block;
            height: 100%;
            background: var(--good);
        }
        .completeness-bar.warn > span { background: var(--warn); }
        .completeness-bar.bad  > span { background: var(--bad); }

        .warning-box {
            background: rgba(245, 184, 0, 0.08);
            border-left: 3px solid var(--warn);
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.88rem;
        }
        .export-bar {
            display: flex;
            gap: 10px;
            margin-top: 1rem;
        }
        .btn-export {
            padding: 10px 18px;
            background: var(--accent);
            color: #000;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-export:hover { opacity: 0.9; }
        .btn-export.secondary {
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
<header class="topbar">
    <h1>📊 OTE měsíční výkaz</h1>
    <div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
        <a href="index.php" style="color:var(--text-dim)">← Admin</a>
        · <a href="plants_ote.php" style="color:var(--text-dim)">🏛️ Metadata</a>
        · <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?>
        · <a href="logout.php" style="color:var(--text-dim)">Odhlásit</a>
    </div>
</header>

<main>
    <!-- Výběr období -->
    <form method="get" class="period-bar">
        <label>Období:</label>
        <select name="month">
            <?php foreach ($months as $i => $m): ?>
                <option value="<?= $i + 1 ?>" <?= $month === ($i + 1) ? 'selected' : '' ?>>
                    <?= ucfirst($m) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="year" value="<?= $year ?>" min="2024" max="2030" style="width:80px">
        <button type="submit" class="btn btn-primary" style="background:var(--accent);color:#000;border:none;padding:8px 16px;border-radius:4px;font-weight:600;cursor:pointer">
            Zobrazit
        </button>
        <span style="color:var(--text-dim);font-size:0.85rem;margin-left:auto">
            <?= $periodFrom ?> – <?= $periodTo ?>
        </span>
    </form>

    <!-- Statistiky -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-num"><?= number_format($totalProduction, 0, ',', ' ') ?></span>
            <div class="stat-label">kWh celkem (GCR_2)</div>
        </div>
        <div class="stat-card">
            <span class="stat-num"><?= count($plants) ?></span>
            <div class="stat-label">aktivních FVE</div>
        </div>
        <div class="stat-card">
            <span class="stat-num"><?= $avgCompleteness ?>%</span>
            <div class="stat-label">úplnost dat</div>
        </div>
        <?php if ($totalSupported > 0): ?>
        <div class="stat-card">
            <span class="stat-num"><?= number_format($totalSupported, 0, ',', ' ') ?></span>
            <div class="stat-label">kWh s podporou (RES_18)</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($avgCompleteness < 90): ?>
        <div class="warning-box">
            ⚠ <strong>Úplnost dat je pod 90 %.</strong> V tabulce <code>production_daily</code> chybí některé dny — výkaz nemusí být přesný. Zkontroluj <code>cron/fetch_realtime.php</code> a <code>cron/fetch_daily.php</code>.
        </div>
    <?php endif; ?>

    <!-- Tabulka výkazu -->
    <div style="overflow-x:auto;border:1px solid var(--border);border-radius:6px">
    <table class="ote-report-table">
        <thead>
            <tr>
                <th style="width:36px;text-align:center"><input type="checkbox" id="chk-all" title="Vybrat vše" style="width:16px;height:16px;cursor:pointer"></th>
                    <th class="text-left">FVE</th>
                <th>GCR_1<br><small>kWp</small></th>
                <th>GCR_2<br><small>svorkova kWh</small></th>
                <th>GCR_3<br><small>spotreba kWh</small></th>
                <th>RES_18<br><small>podpora kWh</small></th>
                <th>Dní</th>
                <th>Úplnost</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="text-align:center;padding:8px 4px;width:36px">
                        <input type="checkbox" name="include_plant[]" value="<?= $r['id'] ?>"
                            class="plant-chk" style="width:16px;height:16px;cursor:pointer"
                            <?= !empty($r['ote_id']) ? 'checked' : '' ?>>
                    </td>
                    <td class="text-left">
                        <?= htmlspecialchars($r['name']) ?>
                        <div class="meta-row">
                            ID výrobny: <?= htmlspecialchars($r['ote_vyrobna_id'] ?: '—') ?>
                            <?php if ($r['ean_code']): ?>
                                · EAN: <?= htmlspecialchars($r['ean_code']) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= number_format($r['gcr1_installed_kwp'], 2, ',', ' ') ?></td>
                    <td><strong><?= number_format($r['gcr2_production_kwh'], 1, ',', ' ') ?></strong></td>
                    <td><?= number_format($r['gcr3_aux_kwh'], 1, ',', ' ') ?></td>
                    <td><?= $r['res18_supported_kwh'] > 0 ? number_format($r['res18_supported_kwh'], 1, ',', ' ') : '—' ?></td>
                    <td><?= $r['days_with_data'] ?> / <?= $daysInMonth ?></td>
                    <td>
                        <?php
                        $cls = $r['completeness'] >= 95 ? '' : ($r['completeness'] >= 75 ? 'warn' : 'bad');
                        ?>
                        <span class="completeness-bar <?= $cls ?>"><span style="width:<?= $r['completeness'] ?>%"></span></span>
                        <?= $r['completeness'] ?>%
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:var(--surface-2);font-weight:600;border-top:2px solid var(--border)">
                <td class="text-left">Σ Celkem</td>
                <td><?= number_format(array_sum(array_column($rows, 'gcr1_installed_kwp')), 2, ',', ' ') ?></td>
                <td><?= number_format($totalProduction, 1, ',', ' ') ?></td>
                <td>0,0</td>
                <td><?= $totalSupported > 0 ? number_format($totalSupported, 1, ',', ' ') : '—' ?></td>
                <td>—</td>
                <td><?= $avgCompleteness ?>%</td>
            </tr>
        </tfoot>
    </table>
    </div>

    <!-- Export tlačítka -->
    <div class="export-bar">
        <a href="?year=<?= $year ?>&month=<?= $month ?>&export=csv" class="btn-export">
            📥 Export CSV
        </a>
        <a href="?year=<?= $year ?>&month=<?= $month ?>&export=xml" class="btn-export secondary">
            📥 Export XML (interní)
        </a>
        <select id="msg-code-select" class="btn-export secondary" style="background:#222;color:#fff;cursor:pointer;padding:8px 12px;border:1px solid #444;border-radius:4px">
            <option value="PD1">PD1 - Měsíční výkaz výroby z OZE</option>
            <option value="PDR">PDR - Data svorkové výroby vnořeného výrobce</option>
        </select>
        <button type="button" onclick="exportResdata()" class="btn-export" style="background:var(--good);color:#000;border:none;cursor:pointer">
            📤 Export RESDATA (OTE upload)
        </button>
        <span style="color:var(--text-dim);font-size:0.85rem;margin-left:auto;align-self:center">
            CSV pro CS OTE portál (přepsání) · XML pro budoucí WSDL upload
        </span>
    </div>

    <p style="color:var(--text-dim);font-size:0.78rem;margin-top:1.5rem">
        <strong>Poznámka:</strong> Výkaz je generován z dat <code>production_daily</code> (GCR_2 = svorková výroba).
        Hodnoty GCR_6/GCR_7 (dodávka/odběr DS) se v CS OTE načítají automaticky od distributora.
        Termín podání: do <strong>10. kalendářního dne</strong> následujícího měsíce.
    </p>
</main>
<script>
// Select-all checkbox
document.getElementById('chk-all').addEventListener('change', function() {
    document.querySelectorAll('.plant-chk').forEach(c => c.checked = this.checked);
});
// Sync select-all stav
document.querySelectorAll('.plant-chk').forEach(c => {
    c.addEventListener('change', () => {
        const all = document.querySelectorAll('.plant-chk');
        const checked = document.querySelectorAll('.plant-chk:checked');
        document.getElementById('chk-all').indeterminate = checked.length > 0 && checked.length < all.length;
        document.getElementById('chk-all').checked = checked.length === all.length;
    });
});
// Export RESDATA jen pro zaškrtnuté FVE
function exportResdata() {
    const ids = Array.from(document.querySelectorAll('.plant-chk:checked')).map(c => c.value);
    if (ids.length === 0) { alert('Zaškrtni alespoň jednu FVE'); return; }
    const msgCode = document.getElementById('msg-code-select').value;
    const params = new URLSearchParams({
        year: '<?= $year ?>',
        month: '<?= $month ?>',
        export: 'xml_resdata',
        msg_code: msgCode,
    });
    ids.forEach(id => params.append('plants[]', id));
    window.location.href = '?' + params.toString();
}
</script>
</body>
</html>


