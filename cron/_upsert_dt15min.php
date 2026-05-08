<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
use FveMonitor\Lib\Database;

$payload = json_decode(file_get_contents('php://stdin'), true);
if (!$payload) { fwrite(STDERR, 'invalid JSON'); exit(1); }

$pdo = Database::pdo();
$stmt = $pdo->prepare(
    'INSERT INTO spot_prices_dt15min
     (delivery_day, period, time_from,
      price_15min_eur, price_60min_eur, volume_mwh,
      buy_15min_mwh, buy_60min_mwh, sell_15min_mwh, sell_60min_mwh,
      saldo_mwh, export_mwh, import_mwh,
      price_15min_czk, price_60min_czk, eur_czk_rate)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        price_15min_eur=VALUES(price_15min_eur),
        price_60min_eur=VALUES(price_60min_eur),
        volume_mwh=VALUES(volume_mwh),
        buy_15min_mwh=VALUES(buy_15min_mwh),
        buy_60min_mwh=VALUES(buy_60min_mwh),
        sell_15min_mwh=VALUES(sell_15min_mwh),
        sell_60min_mwh=VALUES(sell_60min_mwh),
        saldo_mwh=VALUES(saldo_mwh),
        export_mwh=VALUES(export_mwh),
        import_mwh=VALUES(import_mwh),
        price_15min_czk=VALUES(price_15min_czk),
        price_60min_czk=VALUES(price_60min_czk),
        eur_czk_rate=VALUES(eur_czk_rate)'
);

$rate = $payload['rate'];
$day = $payload['day'];
$count = 0;
foreach ($payload['rows'] as $r) {
    $czk15 = ($r['price_15min'] !== null && $rate) ? round($r['price_15min'] * $rate, 2) : null;
    $czk60 = ($r['price_60min'] !== null && $rate) ? round($r['price_60min'] * $rate, 2) : null;
    $stmt->execute([
        $day, $r['period'], $r['time_from'],
        $r['price_15min'], $r['price_60min'], $r['volume'],
        $r['buy_15min'], $r['buy_60min'], $r['sell_15min'], $r['sell_60min'],
        $r['saldo'], $r['export'], $r['imp'],
        $czk15, $czk60, $rate
    ]);
    $count++;
}
echo $count;
