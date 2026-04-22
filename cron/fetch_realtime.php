<?php
/**
 * Cron: každých 15 min — stáhnout aktuální výkon všech aktivních elektráren.
 *
 * Crontab:
 *   *\/15 * * * * php /var/www/sunlai.org/fve/cron/fetch_realtime.php >> /var/www/sunlai.org/fve/logs/realtime.log 2>&1
 *
 * Strategie:
 *   - Pro 'isolarcloud' provider použijeme 1× volání getPowerStationList
 *     a obsloužíme všechny iSolarCloud plants najednou (efektivnější)
 *   - Pro 'mock' provider zachováváme původní logiku per plant
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\ProviderFactory;
use FveMonitor\Lib\ISolarCloudProvider;

$ts = date('Y-m-d H:i:s');

// === 1) iSolarCloud - batch volání pro všech 8 najednou ===
try {
    $hasIsolarcloud = Database::one(
        "SELECT COUNT(*) AS c FROM plants
         WHERE is_active = 1 AND provider = 'isolarcloud'
           AND provider_ps_id IS NOT NULL"
    );

    if (($hasIsolarcloud['c'] ?? 0) > 0) {
        $isc = new ISolarCloudProvider();
        $result = $isc->updateRealtimeForAll();
        echo "[$ts] iSolarCloud: {$result['updated']}/{$result['total']} aktualizováno\n";
        foreach ($result['errors'] as $err) {
            echo "[$ts]   CHYBA: $err\n";
        }
    }
} catch (\Throwable $e) {
    echo "[$ts] iSolarCloud GLOBAL CHYBA: {$e->getMessage()}\n";
}

// === 2) SolarEdge - batch (per site, ale obsluhujeme všechny najednou) ===
try {
    $hasSolaredge = Database::one(
        "SELECT COUNT(*) AS c FROM plants
         WHERE is_active = 1 AND provider = 'solaredge'
           AND provider_ps_id IS NOT NULL"
    );

    if (($hasSolaredge['c'] ?? 0) > 0) {
        $se = new \FveMonitor\Lib\SolarEdgeProvider();
        $result = $se->updateRealtimeForAll();
        echo "[$ts] SolarEdge: {$result['updated']}/{$result['total']} aktualizováno\n";
        foreach ($result['errors'] as $err) {
            echo "[$ts]   CHYBA: $err\n";
        }
    }
} catch (\Throwable $e) {
    echo "[$ts] SolarEdge GLOBAL CHYBA: {$e->getMessage()}\n";
}

// === 3) Mock plants (ostatní providery per-plant) ===
$plants = Database::all(
    "SELECT * FROM plants
     WHERE is_active = 1 AND provider NOT IN ('isolarcloud', 'solaredge')"
);

foreach ($plants as $plant) {
    try {
        $provider = ProviderFactory::forPlant($plant);
        $data = $provider->getRealtime($plant['provider_ps_id'] ?? '');

        Database::pdo()->prepare(
            'INSERT INTO production_realtime (plant_id, ts, power_kw, energy_kwh)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                power_kw = VALUES(power_kw),
                energy_kwh = VALUES(energy_kwh)'
        )->execute([
            $plant['id'],
            $data['ts'],
            $data['power_kw'],
            $data['energy_kwh_today'],
        ]);

        echo "[$ts] {$plant['code']}: {$data['power_kw']} kW / {$data['energy_kwh_today']} kWh\n";
    } catch (\Throwable $e) {
        echo "[$ts] CHYBA {$plant['code']}: {$e->getMessage()}\n";
    }
}

// Úklid - drž jen 7 denní okno realtime
Database::pdo()->exec(
    'DELETE FROM production_realtime WHERE ts < NOW() - INTERVAL 7 DAY'
);
