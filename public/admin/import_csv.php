<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Auth;

$user = Auth::currentUser();

$report = null;
$preview = null;
$csvRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'preview';

    // Náhled - upload CSV a parse
    if ($action === 'preview' && !empty($_FILES['csv']['tmp_name'])) {
        $csvRows = parseIsolarCloudCsv($_FILES['csv']['tmp_name']);
        $preview = generatePreview($csvRows);

        // Uložíme parsované řádky do session pro následný import
        \FveMonitor\Lib\Auth::start();
        $_SESSION['csv_import_rows'] = $csvRows;
    }

    // Import - z dat uložených v session při náhledu
    if ($action === 'import') {
        \FveMonitor\Lib\Auth::start();
        $csvRows = $_SESSION['csv_import_rows'] ?? [];

        if (empty($csvRows)) {
            $report = ['error' => 'Žádná data k importu. Nejprve nahraj CSV a klikni Náhled.'];
        } else {
            $strategy = $_POST['strategy'] ?? 'higher_wins';
            $report = doImport($csvRows, $strategy);
            unset($_SESSION['csv_import_rows']);
        }
    }
}

function parseIsolarCloudCsv(string $path): array
{
    $rows = [];
    $f = fopen($path, 'r');
    if (!$f) return [];

    $first = fread($f, 3);
    if ($first !== "\xEF\xBB\xBF") fseek($f, 0);

    $header = fgetcsv($f, 0, ',');
    if (!$header) { fclose($f); return []; }

    $colCode = findColumn($header, ['Název elektrárny', 'Plant Name', 'Power Station']);
    $colDate = findColumn($header, ['Čas', 'Date', 'Time', 'Day']);
    $colKwh  = findColumn($header, ['Denní výnos', 'Daily Yield', 'Today Energy', 'Energy', 'kWh']);

    if ($colCode === null || $colDate === null || $colKwh === null) {
        fclose($f);
        return ['_error' => 'Nelze najít sloupce. Hlavička: ' . implode(', ', $header)];
    }

    while (($row = fgetcsv($f, 0, ',')) !== false) {
        if (count($row) < 3) continue;
        $code = trim($row[$colCode] ?? '');
        $date = trim($row[$colDate] ?? '');
        $kwh  = trim($row[$colKwh] ?? '');
        if ($code === '' || $date === '' || $kwh === '') continue;

        $code = strtoupper($code);

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt) $dt = DateTimeImmutable::createFromFormat('d.m.Y', $date);
        if (!$dt) continue;

        $rows[] = [
            'code' => $code,
            'date' => $dt->format('Y-m-d'),
            'kwh'  => (float) str_replace([',', ' '], ['.', ''], $kwh),
        ];
    }
    fclose($f);
    return $rows;
}

function findColumn(array $header, array $candidates): ?int
{
    foreach ($candidates as $cand) {
        foreach ($header as $i => $h) {
            if (mb_strtolower(trim($h)) === mb_strtolower($cand)) return $i;
            if (str_contains(mb_strtolower($h), mb_strtolower($cand))) return $i;
        }
    }
    return null;
}

function generatePreview(array $rows): array
{
    if (isset($rows['_error'])) return ['error' => $rows['_error']];
    if (empty($rows)) return ['error' => 'CSV neobsahuje žádné platné řádky.'];

    $plants = Database::all('SELECT id, code, name FROM plants WHERE is_active = 1');
    $plantsByCode = [];
    foreach ($plants as $p) $plantsByCode[strtoupper($p['code'])] = $p;

    // Per FVE statistiky + porovnání s DB
    $byCode = [];
    $unmatched = [];

    foreach ($rows as $r) {
        $code = $r['code'];

        if (!isset($plantsByCode[$code])) {
            if (!in_array($code, $unmatched, true)) $unmatched[] = $code;
            continue;
        }

        if (!isset($byCode[$code])) {
            $byCode[$code] = [
                'name'    => $plantsByCode[$code]['name'],
                'plant_id' => (int) $plantsByCode[$code]['id'],
                'days'    => 0, 'kwh' => 0.0,
                'first'   => null, 'last' => null,
                'new'     => 0, 'identical' => 0,
                'higher_csv' => 0, 'lower_csv' => 0,
                'conflicts' => [],
            ];
        }

        $byCode[$code]['days']++;
        $byCode[$code]['kwh'] += $r['kwh'];
        if ($byCode[$code]['first'] === null || $r['date'] < $byCode[$code]['first']) $byCode[$code]['first'] = $r['date'];
        if ($byCode[$code]['last']  === null || $r['date'] > $byCode[$code]['last']) $byCode[$code]['last'] = $r['date'];

        // Porovnej s DB
        $existing = Database::one(
            'SELECT energy_kwh FROM production_daily WHERE plant_id = ? AND day = ?',
            [$plantsByCode[$code]['id'], $r['date']]
        );

        if ($existing === null) {
            $byCode[$code]['new']++;
        } else {
            $dbKwh = (float) $existing['energy_kwh'];
            $diff = abs($dbKwh - $r['kwh']);

            if ($diff < 0.01) {
                $byCode[$code]['identical']++;
            } else {
                $isHigher = $r['kwh'] > $dbKwh;
                if ($isHigher) {
                    $byCode[$code]['higher_csv']++;
                } else {
                    $byCode[$code]['lower_csv']++;
                }
                // Uložíme až 5 příkladů konfliktů per FVE
                if (count($byCode[$code]['conflicts']) < 5) {
                    $byCode[$code]['conflicts'][] = [
                        'date' => $r['date'],
                        'csv'  => $r['kwh'],
                        'db'   => $dbKwh,
                        'higher' => $isHigher ? 'csv' : 'db',
                    ];
                }
            }
        }
    }

    return [
        'total_rows' => count($rows),
        'unique_fve' => count($byCode),
        'matched'    => $byCode,
        'unmatched'  => $unmatched,
    ];
}

function doImport(array $rows, string $strategy): array
{
    if (isset($rows['_error']) || empty($rows)) {
        return ['error' => $rows['_error'] ?? 'Žádná data'];
    }

    $plants = Database::all('SELECT id, code, name FROM plants WHERE is_active = 1');
    $plantsByCode = [];
    foreach ($plants as $p) $plantsByCode[strtoupper($p['code'])] = $p;

    $stats = [];
    $unmatched = [];

    $insertStmt = Database::pdo()->prepare(
        'INSERT INTO production_daily (plant_id, day, energy_kwh, peak_kw)
         VALUES (?, ?, ?, 0)'
    );
    $updateStmt = Database::pdo()->prepare(
        'UPDATE production_daily SET energy_kwh = ? WHERE plant_id = ? AND day = ?'
    );

    foreach ($rows as $r) {
        $code = $r['code'];

        if (!isset($plantsByCode[$code])) {
            if (!in_array($code, $unmatched, true)) $unmatched[] = $code;
            continue;
        }

        $plantId = (int) $plantsByCode[$code]['id'];

        if (!isset($stats[$code])) {
            $stats[$code] = [
                'name' => $plantsByCode[$code]['name'],
                'inserted' => 0, 'updated' => 0, 'kept' => 0, 'identical' => 0,
                'kwh_csv' => 0.0, 'kwh_final' => 0.0,
            ];
        }

        $stats[$code]['kwh_csv'] += $r['kwh'];

        $existing = Database::one(
            'SELECT energy_kwh FROM production_daily WHERE plant_id = ? AND day = ?',
            [$plantId, $r['date']]
        );

        if ($existing === null) {
            // Nový záznam
            try {
                $insertStmt->execute([$plantId, $r['date'], $r['kwh']]);
                $stats[$code]['inserted']++;
                $stats[$code]['kwh_final'] += $r['kwh'];
            } catch (\Throwable $e) {}
            continue;
        }

        $dbKwh = (float) $existing['energy_kwh'];
        $diff = abs($dbKwh - $r['kwh']);

        if ($diff < 0.01) {
            $stats[$code]['identical']++;
            $stats[$code]['kwh_final'] += $dbKwh;
            continue;
        }

        // Konflikt - rozhoduje strategie
        $shouldOverwrite = false;
        if ($strategy === 'csv_wins') {
            $shouldOverwrite = true;
        } elseif ($strategy === 'db_wins') {
            $shouldOverwrite = false;
        } elseif ($strategy === 'higher_wins') {
            $shouldOverwrite = $r['kwh'] > $dbKwh;
        }

        if ($shouldOverwrite) {
            try {
                $updateStmt->execute([$r['kwh'], $plantId, $r['date']]);
                $stats[$code]['updated']++;
                $stats[$code]['kwh_final'] += $r['kwh'];
            } catch (\Throwable $e) {}
        } else {
            $stats[$code]['kept']++;
            $stats[$code]['kwh_final'] += $dbKwh;
        }
    }

    return [
        'success'    => true,
        'strategy'   => $strategy,
        'stats'      => $stats,
        'unmatched'  => $unmatched,
        'total_rows' => count($rows),
    ];
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import CSV — FVE Monitor Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .upload-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .upload-card h2 { margin-top: 0; font-size: 1.1rem; }
        .file-input {
            margin: 1rem 0;
            padding: 1rem;
            background: var(--surface-2);
            border: 2px dashed var(--border);
            border-radius: 6px;
            text-align: center;
        }
        .btn-action {
            padding: 10px 18px;
            background: var(--accent);
            color: #000;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 8px;
        }
        .btn-action.secondary {
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-action:disabled { opacity: 0.5; cursor: not-allowed; }
        .preview-table, .report-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.85rem;
            margin-top: 1rem;
        }
        .preview-table th, .report-table th {
            background: var(--surface-2);
            color: var(--text-dim);
            text-align: center;
            padding: 10px;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .preview-table td, .report-table td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border);
        }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .text-left { text-align: left; }
        .alert-error { background: rgba(248, 81, 73, 0.1); border-left: 3px solid var(--bad); padding: 12px 16px; border-radius: 4px; margin-bottom: 1rem; color: var(--bad); }
        .alert-warn { background: rgba(245, 184, 0, 0.08); border-left: 3px solid var(--warn); padding: 12px 16px; border-radius: 4px; margin-bottom: 1rem; }
        .alert-success { background: rgba(63, 185, 80, 0.1); border-left: 3px solid var(--good); padding: 12px 16px; border-radius: 4px; margin-bottom: 1rem; color: var(--good); }
        .help-text { background: rgba(245, 184, 0, 0.05); border-left: 3px solid var(--accent); padding: 12px 16px; border-radius: 4px; margin-bottom: 1rem; font-size: 0.88rem; }
        .help-text code { background: var(--surface-2); padding: 2px 6px; border-radius: 3px; font-size: 0.85em; }
        .strategy-card {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .strategy-card label {
            display: block;
            padding: 10px 12px;
            margin-bottom: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 4px;
            cursor: pointer;
        }
        .strategy-card label:hover { border-color: var(--accent); }
        .strategy-card input[type="radio"] { margin-right: 8px; }
        .strategy-card label.selected { border-color: var(--accent); background: rgba(245, 184, 0, 0.05); }
        .conflicts-detail {
            margin-top: 8px;
            padding: 6px 10px;
            background: var(--surface-2);
            border-radius: 4px;
            font-size: 0.8rem;
            color: var(--text-dim);
        }
        .pill {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .pill-new      { background: rgba(63, 185, 80, 0.2); color: var(--good); }
        .pill-identical { background: var(--surface-2); color: var(--text-dim); }
        .pill-higher   { background: rgba(245, 184, 0, 0.2); color: var(--warn); }
        .pill-lower    { background: rgba(248, 81, 73, 0.2); color: var(--bad); }
    </style>
</head>
<body>
<header class="topbar">
    <h1>📥 Import CSV z iSolarCloud</h1>
    <div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
        <a href="index.php" style="color:var(--text-dim)">← Admin</a>
        · <a href="ote_report.php" style="color:var(--text-dim)">📊 OTE výkaz</a>
        · <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?>
        · <a href="logout.php" style="color:var(--text-dim)">Odhlásit</a>
    </div>
</header>

<main>
    <div class="help-text">
        <strong>📋 Postup:</strong><br>
        1. V iSolarCloud stáhni měsíční hlášení jako CSV<br>
        2. Nahraj soubor a klikni <strong>Náhled</strong> — systém porovná s existujícími daty<br>
        3. Zkontroluj konflikty (nové/identické/vyšší/nižší) → vyber strategii<br>
        4. Klikni <strong>Importovat</strong> — data se uloží podle zvolené strategie<br>
    </div>

    <!-- Upload form -->
    <div class="upload-card">
        <h2>1️⃣ Nahrát CSV soubor</h2>
        <form method="post" enctype="multipart/form-data">
            <div class="file-input">
                <input type="file" name="csv" accept=".csv" required>
                <div style="margin-top:8px;color:var(--text-dim);font-size:0.85rem">
                    Akceptováno: <code>.csv</code> z iSolarCloud měsíčního exportu
                </div>
            </div>
            <button type="submit" name="action" value="preview" class="btn-action">
                👁️ Náhled (porovnat s DB)
            </button>
        </form>
    </div>

    <!-- Náhled -->
    <?php if ($preview !== null): ?>
        <div class="upload-card">
            <h2>2️⃣ Náhled importu (porovnání s DB)</h2>

            <?php if (isset($preview['error'])): ?>
                <div class="alert-error">⚠ <?= htmlspecialchars($preview['error']) ?></div>
            <?php else: ?>
                <p>
                    <strong>Celkem v CSV:</strong> <?= $preview['total_rows'] ?> řádků
                    · <strong>FVE:</strong> <?= $preview['unique_fve'] ?>
                </p>

                <?php if (!empty($preview['unmatched'])): ?>
                    <div class="alert-warn">
                        ⚠ <strong>Tyto FVE nejsou v DB:</strong>
                        <?= htmlspecialchars(implode(', ', $preview['unmatched'])) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($preview['matched'])): ?>
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th class="text-left">FVE</th>
                                <th>Období</th>
                                <th>Dní</th>
                                <th>Σ kWh</th>
                                <th>🆕 Nové</th>
                                <th>✅ Identické</th>
                                <th>🔄 Vyšší v CSV</th>
                                <th>⚠️ Nižší v CSV</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['matched'] as $code => $m): ?>
                                <tr>
                                    <td class="text-left">
                                        <strong><?= htmlspecialchars($m['name']) ?></strong>
                                        <small style="color:var(--text-dim)">(<?= htmlspecialchars($code) ?>)</small>
                                    </td>
                                    <td class="num"><?= htmlspecialchars($m['first'] . ' – ' . $m['last']) ?></td>
                                    <td class="num"><?= $m['days'] ?></td>
                                    <td class="num"><?= number_format($m['kwh'], 1, ',', ' ') ?></td>
                                    <td class="num">
                                        <?php if ($m['new'] > 0): ?>
                                            <span class="pill pill-new"><?= $m['new'] ?></span>
                                        <?php else: ?>0<?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <?php if ($m['identical'] > 0): ?>
                                            <span class="pill pill-identical"><?= $m['identical'] ?></span>
                                        <?php else: ?>0<?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <?php if ($m['higher_csv'] > 0): ?>
                                            <span class="pill pill-higher"><?= $m['higher_csv'] ?></span>
                                        <?php else: ?>0<?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <?php if ($m['lower_csv'] > 0): ?>
                                            <span class="pill pill-lower"><?= $m['lower_csv'] ?></span>
                                        <?php else: ?>0<?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($m['conflicts'])): ?>
                                    <tr>
                                        <td colspan="8" class="conflicts-detail">
                                            <strong>Příklady konfliktů:</strong>
                                            <?php foreach ($m['conflicts'] as $c): ?>
                                                <span style="margin-right:1rem">
                                                    <?= htmlspecialchars($c['date']) ?>:
                                                    CSV <strong><?= number_format($c['csv'], 1, ',', '') ?></strong>
                                                    vs DB <?= number_format($c['db'], 1, ',', '') ?>
                                                    <?= $c['higher'] === 'csv' ? '🔄' : '⚠️' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3 style="margin-top:1.5rem">3️⃣ Strategie pro konflikty</h3>
                    <form method="post">
                        <div class="strategy-card">
                            <label>
                                <input type="radio" name="strategy" value="higher_wins" checked>
                                <strong>📈 Vyšší vyhrává (doporučeno)</strong>
                                <div style="font-size:0.85rem;color:var(--text-dim);margin-top:4px">
                                    Pro každý den se uloží **vyšší** hodnota.
                                    Bezpečné — chrání před přepsáním přesných dat odhadem.
                                </div>
                            </label>
                            <label>
                                <input type="radio" name="strategy" value="csv_wins">
                                <strong>📥 CSV vyhrává (vždy přepsat z CSV)</strong>
                                <div style="font-size:0.85rem;color:var(--text-dim);margin-top:4px">
                                    iSolarCloud data jsou považována za pravdu — všechny konflikty se přepíšou.
                                    Použij když máš jistotu, že CSV je nejspolehlivější zdroj.
                                </div>
                            </label>
                            <label>
                                <input type="radio" name="strategy" value="db_wins">
                                <strong>🛡 DB vyhrává (zachovat původní)</strong>
                                <div style="font-size:0.85rem;color:var(--text-dim);margin-top:4px">
                                    Importují se **pouze nové dny**, existující se nikdy nepřepíšou.
                                    Bezpečné když máš strach že CSV obsahuje chyby.
                                </div>
                            </label>
                        </div>

                        <button type="submit" name="action" value="import" class="btn-action"
                                onclick="return confirm('Importovat data podle zvolené strategie?')">
                            💾 Importovat
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Report po importu -->
    <?php if ($report !== null): ?>
        <div class="upload-card">
            <h2>📊 Výsledek importu</h2>

            <?php if (isset($report['error'])): ?>
                <div class="alert-error">⚠ <?= htmlspecialchars($report['error']) ?></div>
            <?php else:
                $stratLabel = match($report['strategy']) {
                    'higher_wins' => '📈 Vyšší vyhrává',
                    'csv_wins'    => '📥 CSV vyhrává',
                    'db_wins'     => '🛡 DB vyhrává',
                    default       => $report['strategy'],
                };
            ?>
                <div class="alert-success">
                    ✓ Import dokončen — strategie: <strong><?= $stratLabel ?></strong>
                    · zpracováno <?= $report['total_rows'] ?> řádků
                </div>

                <?php if (!empty($report['unmatched'])): ?>
                    <div class="alert-warn">
                        ⚠ Nespárováno: <?= htmlspecialchars(implode(', ', $report['unmatched'])) ?>
                    </div>
                <?php endif; ?>

                <table class="report-table">
                    <thead>
                        <tr>
                            <th class="text-left">FVE</th>
                            <th>🆕 Vloženo</th>
                            <th>🔄 Aktualizováno</th>
                            <th>🛡 Zachováno</th>
                            <th>✅ Identické</th>
                            <th>Σ Finální kWh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totals = ['inserted' => 0, 'updated' => 0, 'kept' => 0, 'identical' => 0, 'kwh' => 0.0];
                        foreach ($report['stats'] as $code => $s):
                            $totals['inserted'] += $s['inserted'];
                            $totals['updated'] += $s['updated'];
                            $totals['kept'] += $s['kept'];
                            $totals['identical'] += $s['identical'];
                            $totals['kwh'] += $s['kwh_final'];
                        ?>
                            <tr>
                                <td class="text-left">
                                    <strong><?= htmlspecialchars($s['name']) ?></strong>
                                    <small style="color:var(--text-dim)">(<?= htmlspecialchars($code) ?>)</small>
                                </td>
                                <td class="num" style="color:var(--good)">+<?= $s['inserted'] ?></td>
                                <td class="num" style="color:var(--accent)"><?= $s['updated'] ?></td>
                                <td class="num" style="color:var(--text-dim)"><?= $s['kept'] ?></td>
                                <td class="num" style="color:var(--text-dim)"><?= $s['identical'] ?></td>
                                <td class="num"><?= number_format($s['kwh_final'], 1, ',', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--surface-2);font-weight:600;border-top:2px solid var(--border)">
                            <td class="text-left">Σ Celkem</td>
                            <td class="num">+<?= $totals['inserted'] ?></td>
                            <td class="num"><?= $totals['updated'] ?></td>
                            <td class="num"><?= $totals['kept'] ?></td>
                            <td class="num"><?= $totals['identical'] ?></td>
                            <td class="num"><?= number_format($totals['kwh'], 1, ',', ' ') ?></td>
                        </tr>
                    </tfoot>
                </table>

                <p style="margin-top:1rem">
                    <a href="ote_report.php" class="btn-action">📊 Zobrazit OTE výkaz</a>
                    <a href="import_csv.php" class="btn-action secondary">📥 Importovat další měsíc</a>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
