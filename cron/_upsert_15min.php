<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
use FveMonitor\Lib\Database;

$payload = json_decode(file_get_contents('php://stdin'), true);
if (!$payload) { fwrite(STDERR, 'invalid JSON'); exit(1); }

$pdo = Database::pdo();
$stmt = $pdo->prepare(
    'INSERT INTO spot_prices_15min
     (delivery_day, period, time_from, volume_mwh,
      price_avg_eur, price_min_eur, price_max_eur, price_last_eur,
      price_avg_czk, price_min_czk, price_max_czk, eur_czk_rate)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        volume_mwh=VALUES(volume_mwh),
        price_avg_eur=VALUES(price_avg_eur),
        price_min_eur=VALUES(price_min_eur),
        price_max_eur=VALUES(price_max_eur),
        price_last_eur=VALUES(price_last_eur),
        price_avg_czk=VALUES(price_avg_czk),
        price_min_czk=VALUES(price_min_czk),
        price_max_czk=VALUES(price_max_czk),
        eur_czk_rate=VALUES(eur_czk_rate)'
);

$rate = $payload['rate'];
$day = $payload['day'];
$count = 0;
foreach ($payload['rows'] as $r) {
    $czkAvg = ($r['price_avg'] !== null && $rate) ? round($r['price_avg'] * $rate, 2) : null;
    $czkMin = ($r['price_min'] !== null && $rate) ? round($r['price_min'] * $rate, 2) : null;
    $czkMax = ($r['price_max'] !== null && $rate) ? round($r['price_max'] * $rate, 2) : null;
    $stmt->execute([
        $day, $r['period'], $r['time_from'],
        $r['volume'], $r['price_avg'], $r['price_min'], $r['price_max'], $r['price_last'],
        $czkAvg, $czkMin, $czkMax, $rate
    ]);
    $count++;
}
echo $count;
