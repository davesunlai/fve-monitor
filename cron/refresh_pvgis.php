<?php
/**
 * Cron: 1. den v měsíci ve 3:00 — refresh PVGIS predikcí.
 *
 * Crontab:
 *   0 3 1 * * php /var/www/sunlai.org/fve/cron/refresh_pvgis.php >> /var/www/sunlai.org/fve/logs/pvgis.log 2>&1
 *
 * PVGIS data se nemění často (multi-year průměry), stačí 1×/měsíc.
 * Můžeš spustit i ručně po přidání nové elektrárny.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\PVGIS;

$plants = Database::all('SELECT * FROM plants WHERE is_active = 1');
$pvgis = new PVGIS();
$ts = date('Y-m-d H:i:s');

foreach ($plants as $plant) {
    try {
        $rows = $pvgis->refreshForPlant($plant);
        $annual = array_sum(array_column($rows, 'e_m_kwh'));
        echo "[$ts] {$plant['code']}: PVGIS roční odhad {$annual} kWh ("
             . count($rows) . " měsíců)\n";
        // PVGIS rate limit 30/s — buďme slušní
        usleep(200_000);
    } catch (\Throwable $e) {
        echo "[$ts] CHYBA {$plant['code']}: {$e->getMessage()}\n";
    }
}
