<?php
/**
 * Admin — ručně spustí refresh PVGIS pro konkrétní elektrárnu.
 */
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\PVGIS;

$id = (int)($_GET['id'] ?? 0);
$plant = Database::one('SELECT * FROM plants WHERE id = ?', [$id]);
if (!$plant) {
    http_response_code(404);
    die('Elektrárna nenalezena.');
}

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html><head><meta charset='utf-8'><title>PVGIS refresh</title>";
echo "<link rel='stylesheet' href='../assets/style.css'><link rel='stylesheet' href='admin.css'></head><body>";
echo "<header class='topbar'><h1>⟳ PVGIS refresh: " . htmlspecialchars($plant['name']) . "</h1>";
echo "<div class='topbar-meta'><a href='index.php' class='btn btn-ghost'>← Zpět</a></div></header>";
echo "<main><div class='form-card'><pre>";

@ob_flush();
flush();

try {
    $pvgis = new PVGIS();
    $rows = $pvgis->refreshForPlant($plant);

    $byMonth = [];
    foreach ($rows as $r) {
        $m = $r['month'];
        $byMonth[$m] = ($byMonth[$m] ?? 0) + $r['e_m_kwh'];
    }
    ksort($byMonth);
    $annual = array_sum($byMonth);

    echo "✓ PVGIS načteno pro " . htmlspecialchars($plant['name']) . "\n\n";
    echo "Roční odhad: " . round($annual, 1) . " kWh\n\n";
    echo "Měsíční rozpad:\n";
    $months = ['','Leden','Únor','Březen','Duben','Květen','Červen',
               'Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
    foreach ($byMonth as $m => $kwh) {
        printf("  %-10s %8.1f kWh\n", $months[$m] . ':', $kwh);
    }

    echo "\n" . count($rows) . " řádků uloženo v pvgis_monthly.\n";
} catch (\Throwable $e) {
    echo "⚠ CHYBA: " . htmlspecialchars($e->getMessage()) . "\n";
}

echo "</pre>";
echo "<a href='index.php' class='btn btn-primary'>Zpět na seznam</a>";
echo "</div></main></body></html>";
