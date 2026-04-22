<?php
/**
 * Seed elektráren do DB. Spouští se ručně po nasazení.
 *   php cron/seed_plants.php
 *
 * Idempotentní — INSERT ... ON DUPLICATE KEY UPDATE podle code.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;

$plants = require __DIR__ . '/../config/plants.php';

$sql = 'INSERT INTO plants
    (code, name, provider, provider_ps_id, latitude, longitude, peak_power_kwp, tilt_deg, azimuth_deg, system_loss_pct)
    VALUES
    (:code, :name, :provider, :ps_id, :lat, :lon, :kwp, :tilt, :azi, :loss)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        provider = VALUES(provider),
        provider_ps_id = VALUES(provider_ps_id),
        latitude = VALUES(latitude),
        longitude = VALUES(longitude),
        peak_power_kwp = VALUES(peak_power_kwp),
        tilt_deg = VALUES(tilt_deg),
        azimuth_deg = VALUES(azimuth_deg),
        system_loss_pct = VALUES(system_loss_pct)';

$stmt = Database::pdo()->prepare($sql);

foreach ($plants as $p) {
    $stmt->execute([
        ':code' => $p['code'],
        ':name' => $p['name'],
        ':provider' => $p['provider'],
        ':ps_id' => $p['provider_ps_id'],
        ':lat' => $p['latitude'],
        ':lon' => $p['longitude'],
        ':kwp' => $p['peak_power_kwp'],
        ':tilt' => $p['tilt_deg'],
        ':azi' => $p['azimuth_deg'],
        ':loss' => $p['system_loss_pct'],
    ]);
    echo "✓ {$p['code']} — {$p['name']}\n";
}

echo "Hotovo. Vloženo/aktualizováno " . count($plants) . " elektráren.\n";
