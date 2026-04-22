<?php
/**
 * Cron: 23:55 každý den — uzavřít denní výrobu a vyhodnotit alerty.
 *
 * Crontab:
 *   55 23 * * * php /var/www/sunlai.org/fve/cron/fetch_daily.php >> /var/www/sunlai.org/fve/logs/daily.log 2>&1
 *
 * Postup:
 *   1) Pro každou aktivní elektrárnu přečte poslední záznam realtime za dnešek
 *      → energy_kwh = kumulativní denní výroba
 *   2) Spočte peak_kw z 15min vzorků
 *   3) Uloží do production_daily
 *   4) Spustí Predictor::evaluateAlerts
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Predictor;

$today = date('Y-m-d');
$ts    = date('Y-m-d H:i:s');

$plants = Database::all('SELECT id, code FROM plants WHERE is_active = 1');
$predictor = new Predictor();

foreach ($plants as $plant) {
    $stats = Database::one(
        'SELECT MAX(energy_kwh) AS energy, MAX(power_kw) AS peak
         FROM production_realtime
         WHERE plant_id = ? AND DATE(ts) = ?',
        [$plant['id'], $today]
    );

    $energy = (float) ($stats['energy'] ?? 0);
    $peak   = (float) ($stats['peak']   ?? 0);

    Database::pdo()->prepare(
        'INSERT INTO production_daily (plant_id, day, energy_kwh, peak_kw)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            energy_kwh = VALUES(energy_kwh),
            peak_kw    = VALUES(peak_kw)'
    )->execute([$plant['id'], $today, $energy, $peak]);

    echo "[$ts] {$plant['code']}: $energy kWh, peak $peak kW\n";

    // Vyhodnoť alerty
    try {
        $predictor->evaluateAlerts((int) $plant['id']);
    } catch (\Throwable $e) {
        echo "[$ts]  alert eval CHYBA: {$e->getMessage()}\n";
    }
}
