<?php
declare(strict_types=1);

/**
 * Stáhne spotové ceny z OTE-CR denního trhu pro zadané dny.
 * Default: dnes (D) a zítra (D+1).
 *
 * Použití:
 *   php fetch_spot_prices.php                  # dnes + zítra
 *   php fetch_spot_prices.php 2026-05-07       # konkrétní den
 *   php fetch_spot_prices.php 2024-01-01 2026-05-07  # rozsah (backfill)
 */

require __DIR__ . '/../lib/Database.php';

use FveMonitor\Lib\Database;

date_default_timezone_set('Europe/Prague');
$ts = date('Y-m-d H:i:s');

// ─────────────────────────────────────────────────────────
// HTTP GET helper (cURL)
// ─────────────────────────────────────────────────────────
function httpGet(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'FVE-Monitor/1.0 (+https://fve.sunlai.org)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($resp !== false && $code === 200) ? $resp : null;
}

// ─────────────────────────────────────────────────────────
// ČNB kurz EUR pro daný den (denní fixing)
// ─────────────────────────────────────────────────────────
function fetchCnbRate(string $date): ?float
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d) return null;
    $url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt?date=' . $d->format('d.m.Y');
    $body = httpGet($url);
    if (!$body) return null;

    foreach (explode("\n", $body) as $line) {
        $parts = explode('|', $line);
        if (count($parts) >= 5 && trim($parts[3]) === 'EUR') {
            $amount = (int) trim($parts[2]);
            $rate   = (float) str_replace(',', '.', trim($parts[4]));
            return $amount > 0 ? $rate / $amount : null;
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────
// OTE denní trh — chart-data JSON endpoint
// Vrací 24 hodinových cen v EUR/MWh
// ─────────────────────────────────────────────────────────
function fetchOteDay(string $date): ?array
{
    $url = 'https://www.ote-cr.cz/cs/kratkodobe-trhy/elektrina/denni-trh/@@chart-data?report_date=' . $date;
    $body = httpGet($url);
    if (!$body) {
        echo "  ✗ HTTP fetch selhal: $url\n";
        return null;
    }

    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['data']['dataLine'])) {
        echo "  ✗ Neplatná JSON struktura\n";
        return null;
    }

    // Najdi řádek s "Cena (EUR/MWh)"
    $priceRow = null;
    foreach ($json['data']['dataLine'] as $line) {
        $title = $line['title'] ?? '';
        if (stripos($title, 'cena') !== false && stripos($title, 'EUR') !== false) {
            $priceRow = $line;
            break;
        }
    }
    if (!$priceRow || empty($priceRow['point'])) {
        echo "  ✗ Cenový řádek nenalezen\n";
        return null;
    }

    $prices = [];
    foreach ($priceRow['point'] as $p) {
        // x = hodina (1-24), y = cena EUR/MWh
        $hour = ((int) $p['x']) - 1;  // 1→0, 24→23
        if ($hour >= 0 && $hour <= 23) {
            $prices[$hour] = (float) $p['y'];
        }
    }

    return count($prices) >= 23 ? $prices : null;
}

// ─────────────────────────────────────────────────────────
// Sestavení seznamu dnů ke stažení
// ─────────────────────────────────────────────────────────
$args = array_slice($argv, 1);
$days = [];

if (count($args) === 0) {
    // Default: D + D+1
    $days[] = date('Y-m-d');
    $days[] = date('Y-m-d', strtotime('+1 day'));
} elseif (count($args) === 1) {
    $days[] = $args[0];
} elseif (count($args) === 2) {
    $from = new DateTime($args[0]);
    $to   = new DateTime($args[1]);
    while ($from <= $to) {
        $days[] = $from->format('Y-m-d');
        $from->modify('+1 day');
    }
}

echo "[$ts] Stahuji spotové ceny pro " . count($days) . " dnů\n";

$stmt = Database::pdo()->prepare(
    "INSERT INTO spot_prices (delivery_day, hour, price_eur_mwh, price_czk_mwh, eur_czk_rate, source)
     VALUES (?, ?, ?, ?, ?, 'OTE')
     ON DUPLICATE KEY UPDATE
        price_eur_mwh = VALUES(price_eur_mwh),
        price_czk_mwh = VALUES(price_czk_mwh),
        eur_czk_rate  = VALUES(eur_czk_rate)"
);

$okDays   = 0;
$failDays = 0;
$rateCache = [];

foreach ($days as $day) {
    echo "→ $day\n";
    $prices = fetchOteDay($day);
    if (!$prices) {
        $failDays++;
        sleep(1);
        continue;
    }

    // ČNB kurz pro daný den (cache)
    if (!isset($rateCache[$day])) {
        $rateCache[$day] = fetchCnbRate($day);
    }
    $rate = $rateCache[$day];

    foreach ($prices as $hour => $eur) {
        $czk = $rate ? round($eur * $rate, 2) : null;
        $stmt->execute([$day, $hour, $eur, $czk, $rate]);
    }

    $minP = min($prices);
    $maxP = max($prices);
    $avgP = round(array_sum($prices) / count($prices), 2);
    echo sprintf("  ✓ %d hodin uloženo · min %.2f / Ø %.2f / max %.2f EUR/MWh · kurz %s\n",
        count($prices),
        $minP, $avgP, $maxP, $rate ? number_format($rate, 3) : 'N/A');
    $okDays++;

    // Šetři OTE server
    if (count($days) > 5) {
        usleep(300000); // 0.3s
    }
}

echo "\n[$ts] HOTOVO: $okDays OK · $failDays FAIL\n";
