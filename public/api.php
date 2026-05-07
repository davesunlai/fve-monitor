<?php
/**
 * JSON API endpoint — data pro frontend.
 *
 * Endpointy:
 *   GET ?action=summary               — přehled všech elektráren (dashboard)
 *   GET ?action=realtime&plant=ID     — křivka výkonu za dnešek (15min vzorky)
 *   GET ?action=monthly&plant=ID      — měsíční přehled (actual vs PVGIS)
 *   GET ?action=yearly&plant=ID&y=YR  — roční graf 12 měsíců
 *   GET ?action=alerts                — neuznané alerty
 *   POST ?action=ack&id=ALERT_ID      — potvrdit alert
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Predictor;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action  = $_GET['action'] ?? 'summary';
$plantId = isset($_GET['plant']) ? (int) $_GET['plant'] : null;

try {
    $response = match ($action) {
        'summary'  => actionSummary(),
        'realtime' => actionRealtime($plantId),
        'monthly'  => actionMonthly($plantId),
        'yearly'   => actionYearly($plantId, (int) ($_GET['y'] ?? date('Y'))),
        'alerts'   => actionAlerts(),
        'ack'      => actionAck((int) ($_GET['id'] ?? 0)),
        'sparkline'=> actionSparkline(),
        'range'    => actionRange($plantId ?? 0, (int) ($_GET['hours'] ?? 48)),
        'day_realtime' => actionDayRealtime($plantId ?? 0, $_GET['date'] ?? date('Y-m-d')),
        'test_alert'   => actionTestAlert($plantId ?? 0),
        'vapid_key'     => actionVapidKey(),
        'push_subscribe'=> actionPushSubscribe(),
        'push_test'     => actionPushTest((int) ($_GET['id'] ?? 0)),
        'passkey_register_start'  => actionPasskeyRegisterStart(),
        'passkey_register_finish' => actionPasskeyRegisterFinish(),
        'passkey_login_start'     => actionPasskeyLoginStart(),
        'passkey_login_finish'    => actionPasskeyLoginFinish(),
        'passkey_list'            => actionPasskeyList(),
        'passkey_delete'          => actionPasskeyDelete((int) ($_GET['id'] ?? 0)),
        'weather_prediction'      => actionWeatherPrediction((int) ($_GET['plant'] ?? 0)),
        'weather_summary'         => actionWeatherSummary(),
        'spot_prices'             => actionSpotPrices($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['day'] ?? null, $_GET['granularity'] ?? 'hour'),
        'spot_calculator'         => actionSpotCalculator(),
        default    => ['error' => 'Neznámá akce: ' . $action],
    };
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ───── handlery ─────

function actionSummary(): array
{
    $plants = Database::all('SELECT * FROM plants WHERE is_active = 1 ORDER BY name');
    $predictor = new Predictor();
    $out = [];
    foreach ($plants as $p) {
        $latest = Database::one(
            'SELECT power_kw, energy_kwh, ts FROM production_realtime
             WHERE plant_id = ? ORDER BY ts DESC LIMIT 1',
            [$p['id']]
        );
        $monthly = $predictor->monthlyOverview((int) $p['id']);

        $out[] = [
            'id'              => (int) $p['id'],
            'code'            => $p['code'],
            'latitude'        => (float) $p['latitude'],
            'longitude'       => (float) $p['longitude'],
            'name'            => $p['name'],
            'peak_power_kwp'  => (float) $p['peak_power_kwp'],
            'provider'        => $p['provider'],
            'current_kw'      => (float) ($latest['power_kw'] ?? 0),
            'today_kwh'       => (float) ($latest['energy_kwh'] ?? 0),
            'alarm_count'     => (int) ($p['alarm_count'] ?? 0),
            'fault_status'    => (int) ($p['fault_status'] ?? 0),
            'last_update'     => $latest['ts'] ?? null,
            'month'           => $monthly,
        ];
    }
    return ['plants' => $out, 'generated_at' => date('c')];
}

function actionRealtime(?int $plantId): array
{
    if ($plantId === null) throw new \RuntimeException('Chybí parametr plant');
    $rows = Database::all(
        'SELECT ts, power_kw FROM production_realtime
         WHERE plant_id = ? AND DATE(ts) = CURDATE()
         ORDER BY ts',
        [$plantId]
    );
    return ['plant_id' => $plantId, 'samples' => $rows];
}

function actionMonthly(?int $plantId): array
{
    if ($plantId === null) throw new \RuntimeException('Chybí parametr plant');
    return (new Predictor())->monthlyOverview($plantId);
}

function actionYearly(?int $plantId, int $year): array
{
    if ($plantId === null) throw new \RuntimeException('Chybí parametr plant');
    return (new Predictor())->yearlyOverview($plantId, $year);
}

function actionAlerts(): array
{
    $rows = Database::all(
        'SELECT a.*, p.code, p.name AS plant_name
         FROM alerts a
         JOIN plants p ON p.id = a.plant_id
         WHERE a.acknowledged_at IS NULL
         ORDER BY a.created_at DESC
         LIMIT 100'
    );
    return ['alerts' => $rows, 'count' => count($rows)];
}

function actionAck(int $alertId): array
{
    if ($alertId === 0) throw new \RuntimeException('Chybí ID alertu');

    // Vyžadujeme přihlášení
    if (!\FveMonitor\Lib\Auth::isLoggedIn()) {
        http_response_code(401);
        return ['error' => 'Pro potvrzení alertu je třeba se přihlásit'];
    }
    $user = \FveMonitor\Lib\Auth::currentUser();
    if ($user === null) {
        http_response_code(401);
        return ['error' => 'Session vypršela'];
    }

    // Přečti komentář z POST body (JSON nebo form)
    $note = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        $note = is_array($json) ? ($json['note'] ?? null) : null;
        if ($note === null) {
            $note = $_POST['note'] ?? null;
        }
    }
    $note = $note !== null ? trim((string) $note) : null;
    if ($note === '') $note = null;

    $stmt = Database::pdo()->prepare(
        'UPDATE alerts
         SET acknowledged_at = NOW(),
             acknowledged_by = ?,
             acknowledgement_note = ?
         WHERE id = ? AND acknowledged_at IS NULL'
    );
    $stmt->execute([$user['id'], $note, $alertId]);

    return [
        'ok'       => true,
        'affected' => $stmt->rowCount(),
        'by'       => $user['username'],
    ];
}


function actionSparkline(): array
{
    $plants = Database::all('SELECT id, peak_power_kwp FROM plants WHERE is_active = 1');
    $out = [];
    foreach ($plants as $p) {
        // 48h dat, vzorkujeme každých ~30 min = cca 96 bodů
        $rows = Database::all(
            'SELECT ts, power_kw FROM production_realtime
             WHERE plant_id = ? AND ts > NOW() - INTERVAL 48 HOUR
             ORDER BY ts ASC',
            [$p['id']]
        );
        $peakKwp = max(1.0, (float) $p['peak_power_kwp']);
        $points = [];
        foreach ($rows as $r) {
            $points[] = [
                't' => $r['ts'],
                'p' => round((float)$r['power_kw'] / $peakKwp * 100, 1),  // % nominálu
            ];
        }
        $out[$p['id']] = $points;
    }
    return ['plants' => $out];
}

function actionRange(int $plantId, int $hours): array
{
    $hours = max(1, min(168, $hours));  // 1h až 7 dní
    $rows = Database::all(
        'SELECT ts, power_kw, energy_kwh FROM production_realtime
         WHERE plant_id = ? AND ts > NOW() - INTERVAL ? HOUR
         ORDER BY ts ASC',
        [$plantId, $hours]
    );
    return [
        'plant_id' => $plantId,
        'hours'    => $hours,
        'samples'  => $rows,
    ];
}


/** Vrátí VAPID public key pro frontend (pro subscribe request). */
function actionVapidKey(): array
{
    $config = require __DIR__ . '/../config/config.php';
    $public = $config['vapid']['public_key'] ?? null;
    if (!$public) {
        return ['error' => 'VAPID není nakonfigurován'];
    }
    return ['public_key' => $public];
}

/** Přijme subscription z browseru a uloží do DB. */
function actionPushSubscribe(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['error' => 'POST only'];
    }
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    $endpoint = $body['endpoint']        ?? null;
    $p256dh   = $body['keys']['p256dh']  ?? null;
    $auth     = $body['keys']['auth']    ?? null;
    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? null;

    if (!$endpoint || !$p256dh || !$auth) {
        return ['error' => 'Neúplná subscription data'];
    }

    try {
        FveMonitor\Lib\Database::pdo()->prepare(
            'INSERT INTO push_subscriptions (endpoint, p256dh_key, auth_key, user_agent)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                p256dh_key   = VALUES(p256dh_key),
                auth_key     = VALUES(auth_key),
                user_agent   = VALUES(user_agent),
                last_used_at = NOW()'
        )->execute([$endpoint, $p256dh, $auth, $ua]);
        return ['ok' => true];
    } catch (\Throwable $e) {
        return ['error' => 'DB chyba: ' . $e->getMessage()];
    }
}

/** Pošle testovací push notifikaci na zadaný subscription ID. */
function actionPushTest(int $subId): array
{
    if ($subId <= 0) {
        return ['error' => 'Chybí parametr id (subscription_id)'];
    }

    $config = require __DIR__ . '/../config/config.php';
    $vapid  = $config['vapid'] ?? null;
    if (!$vapid) return ['error' => 'VAPID nenakonfigurováno'];

    $row = FveMonitor\Lib\Database::one(
        'SELECT * FROM push_subscriptions WHERE id = ?', [$subId]
    );
    if (!$row) return ['error' => 'Subscription nenalezena'];

    $sub = Minishlink\WebPush\Subscription::create([
        'endpoint'        => $row['endpoint'],
        'publicKey'       => $row['p256dh_key'],
        'authToken'       => $row['auth_key'],
        'contentEncoding' => 'aes128gcm',
    ]);

    $webPush = new Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject'    => $vapid['subject'],
            'publicKey'  => $vapid['public_key'],
            'privateKey' => $vapid['private_key'],
        ],
    ]);

    $payload = json_encode([
        'title' => '🔔 Test notifikace',
        'body'  => 'FVE Monitor — push notifikace fungují!',
        'icon'  => '/assets/icon-192.png',
        'url'   => '/',
    ]);

    $webPush->queueNotification($sub, $payload);

    $results = [];
    foreach ($webPush->flush() as $report) {
        $results[] = [
            'success'  => $report->isSuccess(),
            'reason'   => $report->getReason(),
            'response' => (string) $report->getResponse()?->getBody(),
        ];
    }
    return ['sent' => count($results), 'results' => $results];
}


// ═══════════════════════════════════════════════════════════
// Passkey / WebAuthn akce
// ═══════════════════════════════════════════════════════════

function actionPasskeyRegisterStart(): array
{
    if (!\FveMonitor\Lib\Auth::isLoggedIn()) {
        http_response_code(401);
        return ['error' => 'Musíš být přihlášen pro přidání passkey'];
    }
    try {
        $user = \FveMonitor\Lib\Auth::currentUser();
        $passkey = new \FveMonitor\Lib\Passkey();
        return $passkey->createRegistrationOptions($user);
    } catch (\Throwable $e) {
        return ['error' => 'Chyba: ' . $e->getMessage()];
    }
}

function actionPasskeyRegisterFinish(): array
{
    if (!\FveMonitor\Lib\Auth::isLoggedIn()) {
        http_response_code(401);
        return ['error' => 'Musíš být přihlášen'];
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['response'])) {
        return ['error' => 'Chybí response v POST body'];
    }

    $deviceName = $data['device_name'] ?? null;
    $passkey = new \FveMonitor\Lib\Passkey();
    $result = $passkey->verifyRegistrationResponse(
        json_encode($data['response']),
        $deviceName
    );

    if (!$result['success']) {
        return ['error' => $result['error'] ?? 'Registrace selhala'];
    }
    return ['ok' => true, 'credential_id' => $result['credential_id']];
}

function actionPasskeyLoginStart(): array
{
    $passkey = new \FveMonitor\Lib\Passkey();
    return $passkey->createLoginOptions();
}

function actionPasskeyLoginFinish(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['error' => 'Chybí data v POST body'];
    }

    $passkey = new \FveMonitor\Lib\Passkey();
    $result = $passkey->verifyLoginResponse(json_encode($data));

    if (!$result['success']) {
        http_response_code(401);
        return ['error' => $result['error'] ?? 'Login selhal'];
    }
    return ['ok' => true, 'user' => $result['user']];
}

function actionPasskeyList(): array
{
    if (!\FveMonitor\Lib\Auth::isLoggedIn()) {
        http_response_code(401);
        return ['error' => 'Musíš být přihlášen'];
    }
    $user = \FveMonitor\Lib\Auth::currentUser();
    $rows = \FveMonitor\Lib\Passkey::getUserCredentials((int) $user['id']);

    // Nevracíme citlivá data - jen metadata
    $out = array_map(fn($r) => [
        'id'           => (int) $r['id'],
        'device_name'  => $r['device_name'],
        'transports'   => $r['transports'],
        'created_at'   => $r['created_at'],
        'last_used_at' => $r['last_used_at'],
    ], $rows);

    return ['credentials' => $out];
}

function actionPasskeyDelete(int $credId): array
{
    if (!\FveMonitor\Lib\Auth::isLoggedIn()) {
        http_response_code(401);
        return ['error' => 'Musíš být přihlášen'];
    }
    if ($credId <= 0) return ['error' => 'Chybí ID'];

    $user = \FveMonitor\Lib\Auth::currentUser();
    $ok = \FveMonitor\Lib\Passkey::deleteCredential($credId, (int) $user['id']);

    return ['ok' => $ok];
}

/**
 * 15min data za daný den pro vybranou FVE
 */
function actionDayRealtime(int $plantId, string $date): array
{
    if ($plantId <= 0) return ['error' => 'plant required'];

    // Validace data
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dt) return ['error' => 'invalid date'];

    $dayStart = $dt->format('Y-m-d 00:00:00');
    $dayEnd   = $dt->format('Y-m-d 23:59:59');

    $rows = \FveMonitor\Lib\Database::all(
        "SELECT ts, power_kw, energy_kwh
         FROM production_realtime
         WHERE plant_id = ? AND ts BETWEEN ? AND ?
         ORDER BY ts ASC",
        [$plantId, $dayStart, $dayEnd]
    );

    if (empty($rows)) {
        return [
            'plant_id' => $plantId,
            'date'     => $date,
            'has_data' => false,
            'message'  => '15-minutová data nejsou pro tento den k dispozici',
        ];
    }

    // Zpracuj data
    $points = [];
    $firstEnergy = (float) $rows[0]['energy_kwh'];
    $maxPower = 0;
    $totalEnergyDelta = 0;

    foreach ($rows as $r) {
        $power = (float) $r['power_kw'];
        $energy = (float) $r['energy_kwh'];
        if ($power > $maxPower) $maxPower = $power;

        $points[] = [
            'time'   => substr($r['ts'], 11, 5), // "HH:MM"
            'ts'     => $r['ts'],
            'power_kw' => $power,
            'energy_kwh' => $energy,
        ];
    }

    $lastEnergy = (float) end($rows)['energy_kwh'];
    $dailyEnergyKwh = $lastEnergy - $firstEnergy;

    return [
        'plant_id'    => $plantId,
        'date'        => $date,
        'has_data'    => true,
        'records'     => count($points),
        'max_power_kw' => round($maxPower, 1),
        'daily_energy_kwh' => round($dailyEnergyKwh, 1),
        'first_time'  => $points[0]['time'] ?? null,
        'last_time'   => end($points)['time'] ?? null,
        'points'      => $points,
    ];
}

/**
 * Test underperform alert pro FVE - vrátí stats bez vytvoření alertu
 */
function actionTestAlert(int $plantId): array
{
    if ($plantId <= 0) return ['error' => 'plant required'];
    $stats = (new \FveMonitor\Lib\Predictor())->computeAlertStats($plantId);
    if ($stats === null) {
        return ['error' => 'Málo dat - alespoň 1 den s daty potřebný'];
    }
    return $stats;
}


// ───── Weather prediction ─────

function actionWeatherPrediction(int $plantId): array
{
    $plant = Database::one(
        'SELECT id, latitude, longitude, peak_power_kwp, system_loss_pct FROM plants WHERE id = ?',
        [$plantId]
    );
    if (!$plant) throw new \RuntimeException("Plant $plantId nenalezena");

    $lat  = (float) $plant['latitude'];
    $lon  = (float) $plant['longitude'];
    $kwp  = (float) $plant['peak_power_kwp'];
    $loss = 1 - ((float) $plant['system_loss_pct']) / 100;

    // Načti průměrný sklon+azimut sekcí (vážený podílem výkonu)
    $sections = Database::all(
        'SELECT tilt_deg, azimuth_deg, power_share_pct FROM plant_sections WHERE plant_id = ?',
        [$plantId]
    );
    $tilt = 35; $azimuth = 0; // defaults
    if (!empty($sections)) {
        $sumShare = array_sum(array_column($sections, 'power_share_pct')) ?: 100;
        $tilt = 0; $azimuth = 0;
        foreach ($sections as $s) {
            $w = $s['power_share_pct'] / $sumShare;
            $tilt    += $s['tilt_deg']    * $w;
            $azimuth += $s['azimuth_deg'] * $w;
        }
        $tilt    = round($tilt);
        $azimuth = round($azimuth);
    }

    // Open-Meteo: global_tilted_irradiance bere v úvahu sklon+azimut panelů
    // 4 dny = přesně rozsah 96h grafu
    $url = "https://api.open-meteo.com/v1/forecast?"
         . "latitude={$lat}&longitude={$lon}"
         . "&hourly=global_tilted_irradiance"
         . "&tilt={$tilt}&azimuth={$azimuth}"
         . "&forecast_days=4&timezone=Europe%2FPrague";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') throw new \RuntimeException('Open-Meteo API nedostupné: ' . $err);

    $data = json_decode($raw, true);
    $times = $data['hourly']['time'] ?? [];
    $gti   = $data['hourly']['global_tilted_irradiance'] ?? [];

    // GTI = záření dopadající na nakloněnou plochu panelů [W/m²]
    // Výkon = GTI/1000 * kWp * PR (performance ratio ~0.80)
    $pr = 0.80;
    $forecast = [];
    foreach ($times as $i => $ts) {
        $kw  = max(0, round(($gti[$i] ?? 0) / 1000 * $kwp * $pr * $loss, 3));
        // Open-Meteo vrací "2026-05-01T14:00", převedeme na "2026-05-01 14:00:00"
        $tsFormatted = str_replace('T', ' ', $ts) . ':00';
        $forecast[] = ['ts' => $tsFormatted, 'power_kw' => $kw];
    }

    // PVGIS denní profil: pro každý den z 4 vygeneruj sinusovou křivku
    // podle měsíčního PVGIS průměru pro aktuální měsíc
    $month = (int) date('n');
    $pvgisRow = Database::one(
        'SELECT SUM(e_m_kwh) as energy_kwh FROM pvgis_monthly WHERE plant_id = ? AND month = ?',
        [$plantId, $month]
    );
    $pvgisMonthKwh = (float) ($pvgisRow['energy_kwh'] ?? 0);
    // Denní průměr = měsíc / počet dnů v měsíci
    $daysInMonth  = (int) date('t');
    $pvgisDayKwh  = $pvgisMonthKwh / $daysInMonth;

    // Vygeneruj hodinový PVGIS profil (sinusový tvar 5:00-21:00) pro 4 dny
    $pvgisProfile = [];
    $startDate = new \DateTime('today midnight');
    for ($d = 0; $d < 4; $d++) {
        $day = clone $startDate;
        $day->modify("+{$d} days");
        // Celková energie dne = pvgisDayKwh, rozložená sin profilem 6h-20h (14h okno)
        // Sum sin(π·i/14) for i=0..14 ≈ 7.5 → scale factor = pvgisDayKwh/7.5
        $scale = $pvgisDayKwh > 0 ? $pvgisDayKwh / 7.5 : 0;
        for ($h = 0; $h < 24; $h++) {
            $kw = 0;
            if ($h >= 6 && $h <= 20) {
                $pos = ($h - 6) / 14.0; // 0..1
                $kw  = round($scale * sin(M_PI * $pos), 3);
            }
            $ts = $day->format('Y-m-d') . ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00:00';
            $pvgisProfile[] = ['ts' => $ts, 'power_kw' => max(0, $kw)];
        }
    }

    return [
        'plant_id'       => $plantId,
        'forecast'       => $forecast,
        'pvgis_profile'  => $pvgisProfile,
        'pvgis_day_kwh'  => round($pvgisDayKwh, 2),
        'generated_at'   => date('c'),
    ];
}

// ───── Weather summary pro dashboard (3 dny pro všechny FVE) ─────

function actionWeatherSummary(): array
{
    $plants = Database::all(
        'SELECT id, latitude, longitude, peak_power_kwp FROM plants WHERE is_active = 1'
    );
    $result = [];
    foreach ($plants as $p) {
        $lat = (float)$p['latitude'];
        $lon = (float)$p['longitude'];
        $kwp = (float)$p['peak_power_kwp'];
        if (!$lat || !$lon) continue;

        $url = "https://api.open-meteo.com/v1/forecast?"
             . "latitude={$lat}&longitude={$lon}"
             . "&daily=weather_code,temperature_2m_max,shortwave_radiation_sum"
             . "&forecast_days=3&timezone=Europe%2FPrague";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!$raw) continue;

        $data = json_decode($raw, true);
        $dates = $data['daily']['time'] ?? [];
        $codes = $data['daily']['weather_code'] ?? [];
        $temps = $data['daily']['temperature_2m_max'] ?? [];
        $rads  = $data['daily']['shortwave_radiation_sum'] ?? [];

        $days = [];
        foreach ($dates as $i => $d) {
            // Odhad výroby: radiace [MJ/m²] * kWp * 0.8 (PR) / 3.6 (MJ→kWh)
            $rad = (float)($rads[$i] ?? 0);
            $estKwh = round($rad * $kwp * 0.8 / 3.6, 0);
            $days[] = [
                'date'         => $d,
                'weather_code' => (int)($codes[$i] ?? 0),
                'tmax'         => round((float)($temps[$i] ?? 0)),
                'est_kwh'      => $estKwh,
            ];
        }
        $result[$p['id']] = $days;
    }
    return ['plants' => $result, 'generated_at' => date('c')];
}


/**
 * Spotové ceny OTE.
 *   ?action=spot_prices                          — dnes + zítra (default)
 *   ?action=spot_prices&day=2026-05-07           — konkrétní den (24 hodin)
 *   ?action=spot_prices&from=2026-04-01&to=2026-04-30  — rozsah (denní průměry)
 */
function actionSpotPrices(?string $from, ?string $to, ?string $day, string $granularity = 'hour'): array
{
    // Validace formátu data
    $isDate = fn($s) => $s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);

    // ─── 15min granularita ───
    if ($granularity === '15min') {
        return actionSpotPrices15min($from, $to, $day, $isDate);
    }

    // 1) Konkrétní den → 24 hodin
    if ($isDate($day)) {
        $rows = \FveMonitor\Lib\Database::all(
            "SELECT delivery_day, hour, price_eur_mwh, price_czk_mwh, eur_czk_rate
             FROM spot_prices WHERE delivery_day = ? ORDER BY hour",
            [$day]
        );
        return [
            'mode'       => 'day',
            'day'        => $day,
            'hours'      => $rows,
            'stats'      => spotStats($rows),
            'generated_at' => date('c'),
        ];
    }

    // 2) Rozsah → denní agregát (min/avg/max za den)
    if ($isDate($from) && $isDate($to)) {
        $daily = \FveMonitor\Lib\Database::all(
            "SELECT delivery_day,
                    ROUND(MIN(price_eur_mwh),2) AS min_eur,
                    ROUND(AVG(price_eur_mwh),2) AS avg_eur,
                    ROUND(MAX(price_eur_mwh),2) AS max_eur,
                    ROUND(MIN(price_czk_mwh),2) AS min_czk,
                    ROUND(AVG(price_czk_mwh),2) AS avg_czk,
                    ROUND(MAX(price_czk_mwh),2) AS max_czk,
                    COUNT(*) AS hours
             FROM spot_prices
             WHERE delivery_day BETWEEN ? AND ?
             GROUP BY delivery_day
             ORDER BY delivery_day",
            [$from, $to]
        );

        // Celková statistika za rozsah
        $all = \FveMonitor\Lib\Database::all(
            "SELECT price_eur_mwh, price_czk_mwh FROM spot_prices
             WHERE delivery_day BETWEEN ? AND ?",
            [$from, $to]
        );

        return [
            'mode'  => 'range',
            'from'  => $from,
            'to'    => $to,
            'days'  => $daily,
            'stats' => spotStats($all),
            'generated_at' => date('c'),
        ];
    }

    // 3) Default: dnes + zítra (po hodinách)
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $rows = \FveMonitor\Lib\Database::all(
        "SELECT delivery_day, hour, price_eur_mwh, price_czk_mwh, eur_czk_rate
         FROM spot_prices WHERE delivery_day IN (?, ?) ORDER BY delivery_day, hour",
        [$today, $tomorrow]
    );

    $byDay = ['today' => [], 'tomorrow' => []];
    foreach ($rows as $r) {
        $key = ($r['delivery_day'] === $today) ? 'today' : 'tomorrow';
        $byDay[$key][] = $r;
    }

    return [
        'mode'           => 'today_tomorrow',
        'today_date'     => $today,
        'tomorrow_date'  => $tomorrow,
        'today'          => $byDay['today'],
        'tomorrow'       => $byDay['tomorrow'],
        'today_stats'    => spotStats($byDay['today']),
        'tomorrow_stats' => spotStats($byDay['tomorrow']),
        'tomorrow_available' => count($byDay['tomorrow']) > 0,
        'generated_at'   => date('c'),
    ];
}

function spotStats(array $rows): array
{
    if (empty($rows)) {
        return ['count' => 0, 'min_eur' => null, 'avg_eur' => null, 'max_eur' => null,
                'min_czk' => null, 'avg_czk' => null, 'max_czk' => null];
    }
    $eur = array_map(fn($r) => (float) $r['price_eur_mwh'], $rows);
    $czk = array_map(fn($r) => (float) ($r['price_czk_mwh'] ?? 0), $rows);
    return [
        'count'   => count($rows),
        'min_eur' => round(min($eur), 2),
        'avg_eur' => round(array_sum($eur) / count($eur), 2),
        'max_eur' => round(max($eur), 2),
        'min_czk' => round(min($czk), 2),
        'avg_czk' => round(array_sum($czk) / count($czk), 2),
        'max_czk' => round(max($czk), 2),
    ];
}

/**
 * 15min spotové ceny (VDT).
 *   ?action=spot_prices&granularity=15min                    — dnes + zítra
 *   ?action=spot_prices&granularity=15min&day=2026-05-07     — 96 period dne
 *   ?action=spot_prices&granularity=15min&from=A&to=B        — agregát po dnech
 */
function actionSpotPrices15min(?string $from, ?string $to, ?string $day, callable $isDate): array
{
    if ($isDate($day)) {
        $rows = \FveMonitor\Lib\Database::all(
            "SELECT delivery_day, period, time_from,
                    price_avg_eur, price_min_eur, price_max_eur,
                    price_avg_czk, price_min_czk, price_max_czk,
                    volume_mwh, eur_czk_rate
             FROM spot_prices_15min WHERE delivery_day = ? ORDER BY period",
            [$day]
        );
        return [
            'mode'         => 'day_15min',
            'granularity'  => '15min',
            'day'          => $day,
            'periods'      => $rows,
            'stats'        => spot15Stats($rows),
            'generated_at' => date('c'),
        ];
    }

    if ($isDate($from) && $isDate($to)) {
        $daily = \FveMonitor\Lib\Database::all(
            "SELECT delivery_day,
                    ROUND(MIN(price_avg_eur),2) AS min_eur,
                    ROUND(AVG(price_avg_eur),2) AS avg_eur,
                    ROUND(MAX(price_avg_eur),2) AS max_eur,
                    ROUND(MIN(price_avg_czk),2) AS min_czk,
                    ROUND(AVG(price_avg_czk),2) AS avg_czk,
                    ROUND(MAX(price_avg_czk),2) AS max_czk,
                    SUM(CASE WHEN price_avg_eur < 0 THEN 1 ELSE 0 END) AS negative_periods,
                    COUNT(*) AS periods
             FROM spot_prices_15min
             WHERE delivery_day BETWEEN ? AND ?
             GROUP BY delivery_day
             ORDER BY delivery_day",
            [$from, $to]
        );
        $all = \FveMonitor\Lib\Database::all(
            "SELECT price_avg_eur AS p_eur, price_avg_czk AS p_czk
             FROM spot_prices_15min WHERE delivery_day BETWEEN ? AND ?",
            [$from, $to]
        );
        return [
            'mode'         => 'range_15min',
            'granularity'  => '15min',
            'from'         => $from,
            'to'           => $to,
            'days'         => $daily,
            'stats'        => spot15Stats($all, 'p_eur', 'p_czk'),
            'generated_at' => date('c'),
        ];
    }

    // Default: dnes + zítra
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $rows = \FveMonitor\Lib\Database::all(
        "SELECT delivery_day, period, time_from,
                price_avg_eur, price_min_eur, price_max_eur,
                price_avg_czk, price_min_czk, price_max_czk,
                volume_mwh, eur_czk_rate
         FROM spot_prices_15min WHERE delivery_day IN (?, ?) ORDER BY delivery_day, period",
        [$today, $tomorrow]
    );
    $byDay = ['today' => [], 'tomorrow' => []];
    foreach ($rows as $r) {
        $key = ($r['delivery_day'] === $today) ? 'today' : 'tomorrow';
        $byDay[$key][] = $r;
    }
    return [
        'mode'               => 'today_tomorrow_15min',
        'granularity'        => '15min',
        'today_date'         => $today,
        'tomorrow_date'      => $tomorrow,
        'today'              => $byDay['today'],
        'tomorrow'           => $byDay['tomorrow'],
        'today_stats'        => spot15Stats($byDay['today']),
        'tomorrow_stats'     => spot15Stats($byDay['tomorrow']),
        'tomorrow_available' => count($byDay['tomorrow']) > 0,
        'generated_at'       => date('c'),
    ];
}

function spot15Stats(array $rows, string $eurKey = 'price_avg_eur', string $czkKey = 'price_avg_czk'): array
{
    if (empty($rows)) {
        return ['count' => 0, 'min_eur' => null, 'avg_eur' => null, 'max_eur' => null,
                'min_czk' => null, 'avg_czk' => null, 'max_czk' => null,
                'negative_periods' => 0];
    }
    $eur = array_filter(array_map(fn($r) => $r[$eurKey] !== null ? (float) $r[$eurKey] : null, $rows), fn($v) => $v !== null);
    $czk = array_filter(array_map(fn($r) => $r[$czkKey] !== null ? (float) $r[$czkKey] : null, $rows), fn($v) => $v !== null);
    $neg = count(array_filter($eur, fn($v) => $v < 0));
    return [
        'count'   => count($rows),
        'min_eur' => round(min($eur), 2),
        'avg_eur' => round(array_sum($eur) / count($eur), 2),
        'max_eur' => round(max($eur), 2),
        'min_czk' => round(min($czk), 2),
        'avg_czk' => round(array_sum($czk) / count($czk), 2),
        'max_czk' => round(max($czk), 2),
        'negative_periods' => $neg,
    ];
}

/**
 * SPOT kalkulačka - rozpočítá měsíční spotřebu kWh přes TDD profil dne,
 * spáruje s 15min/hodinovými cenami a spočítá kompletní fakturu.
 *
 * Parametry GET:
 *   year, month       - období
 *   tdd               - TDD třída (4-8)
 *   tariff            - distribuční sazba (D02d, D25d, D45d, D57d...)
 *   kwh_vt            - měsíční spotřeba ve vysokém tarifu (kWh)
 *   kwh_nt            - měsíční spotřeba v nízkém tarifu (kWh) - pro 2-tarifní sazby
 *   jistic            - jistič (např. "3x25")
 *   tradefee          - poplatek za služby obchodu (Kč/MWh, default 482.79)
 *   monthly_fee       - stálá platba obchodník (Kč/měsíc, default 154.88)
 *   distrib_vt        - distribuce VT Kč/MWh
 *   distrib_nt        - distribuce NT Kč/MWh (může být 0)
 *   jistic_fee        - stálá platba za jistič Kč/měsíc
 *   poze_kwh          - POZE Kč/MWh (default 598.95)
 *   poze_a            - POZE Kč/A/měsíc (default 140.97)
 *   poze_mode         - 'kwh' nebo 'jistic' (počítá se nižší)
 *   include_dph       - 'yes'/'no' (default yes)
 *
 * Vrací: kompletní rozpis faktury + denní/hodinový profil spotřeby
 */
function actionSpotCalculator(): array
{
    // ─── Vstupní parametry ───
    $year   = (int) ($_GET['year']  ?? date('Y'));
    $month  = (int) ($_GET['month'] ?? date('n'));
    $tdd    = (int) ($_GET['tdd']   ?? 4);
    $tariff = $_GET['tariff'] ?? 'D02d';
    $kwhVt  = (float) ($_GET['kwh_vt'] ?? 250);
    $kwhNt  = (float) ($_GET['kwh_nt'] ?? 0);

    // Obchodní část
    $tradeFee   = (float) ($_GET['tradefee']    ?? 482.79);  // Kč/MWh s DPH
    $monthlyFee = (float) ($_GET['monthly_fee'] ?? 154.88);  // Kč/měsíc s DPH

    // Distribuce
    $distribVt = (float) ($_GET['distrib_vt'] ?? 2515.08);  // Kč/MWh s DPH (D02d default)
    $distribNt = (float) ($_GET['distrib_nt'] ?? 0);
    $jisticFee = (float) ($_GET['jistic_fee'] ?? 309.76);   // Kč/měsíc s DPH (D02d 3x25A)

    // POZE
    $pozeKwh   = (float) ($_GET['poze_kwh']  ?? 598.95);
    $pozeA     = (float) ($_GET['poze_a']    ?? 140.97);
    $jisticA   = (int)   ($_GET['jistic_a']  ?? 25);
    $jisticPh  = (int)   ($_GET['jistic_ph'] ?? 3);

    // Pevné poplatky (vždy stejné)
    $danElektrina = 34.24;   // Kč/MWh s DPH
    $sysSluzby    = 198.73;  // Kč/MWh s DPH
    $infraNesit   = 15.57;   // Kč/měsíc s DPH

    // ─── Načti spotové ceny pro daný měsíc (15min preferováno, fallback na hour) ───
    $periodFrom = sprintf('%04d-%02d-01', $year, $month);
    $periodTo   = (new DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');
    $daysInMonth = (int) (new DateTimeImmutable($periodFrom))->format('t');

    // Zkus 15min
    $prices15 = \FveMonitor\Lib\Database::all(
        "SELECT delivery_day, period, time_from, price_avg_czk
         FROM spot_prices_15min WHERE delivery_day BETWEEN ? AND ? ORDER BY delivery_day, period",
        [$periodFrom, $periodTo]
    );
    $useGranularity = '15min';
    $expectedRows = $daysInMonth * 96;

    // Spočítej kolik dnů v období je už uplynulo (kvůli budoucímu měsíci)
    $today = date('Y-m-d');
    $effectiveTo = ($periodTo > $today) ? $today : $periodTo;
    $effectiveDays = (int) ((strtotime($effectiveTo) - strtotime($periodFrom)) / 86400) + 1;
    $expectedAvailable = $effectiveDays * 96;

    // Pokud chybí víc než 10% dostupných dat, fallback na hodinová
    if (count($prices15) < $expectedAvailable * 0.9) {
        $useGranularity = 'hour';
        $pricesHour = \FveMonitor\Lib\Database::all(
            "SELECT delivery_day, hour, price_czk_mwh AS price_avg_czk
             FROM spot_prices WHERE delivery_day BETWEEN ? AND ? ORDER BY delivery_day, hour",
            [$periodFrom, $periodTo]
        );
        $rawPrices = $pricesHour;
    } else {
        $rawPrices = $prices15;
    }

    if (empty($rawPrices)) {
        return ['error' => 'Žádné spotové ceny pro období ' . $periodFrom . ' až ' . $periodTo];
    }

    // ─── TDD profil (zjednodušený - hodinové koeficienty 0-23) ───
    // Suma všech 24 koeficientů = 1.0
    $tddProfiles = getTddProfile();
    $hourProfile = $tddProfiles[$tdd] ?? $tddProfiles[4];

    // ─── Definice nízkého tarifu (NT) - kdy je sazba "lacinější" pásmo ───
    // D02d = 1-tarif (vždy VT), D25d = 8h NT, D45d = 16h NT, D57d = 20h NT
    $ntHours = getTariffNtHours($tariff);

    // ─── Výpočet ───
    $totalKwh = $kwhVt + $kwhNt;
    if ($totalKwh <= 0) {
        return ['error' => 'Spotřeba musí být kladná'];
    }

    // Sečti TDD koeficienty pro VT a NT hodiny → ratio
    $coefVt = 0;
    $coefNt = 0;
    for ($h = 0; $h < 24; $h++) {
        if (in_array($h, $ntHours, true)) {
            $coefNt += $hourProfile[$h];
        } else {
            $coefVt += $hourProfile[$h];
        }
    }

    // Pokud uživatel zadal jen kwh_vt (1tarif), rozdělíme ji ratio koeficientů
    // Pokud zadal oba, použijeme jeho rozdělení ale uvnitř každého pásma necháme TDD
    $useUserSplit = ($kwhNt > 0 && !empty($ntHours));

    // ─── Iterace přes všechny 15min/hodinové bloky a součet nákladů ───
    $silovaCelkem = 0;        // suma za silovou (spot + obchod) Kč
    $kwhSpotreba = [];         // [day][hour][quarter] => kWh
    $minPrice = PHP_FLOAT_MAX; $maxPrice = -PHP_FLOAT_MAX;
    $negKwh = 0;              // kWh spotřebovaných v záporných cenách

    foreach ($rawPrices as $row) {
        $day = $row['delivery_day'];
        $priceCzk = (float) $row['price_avg_czk'];

        if ($useGranularity === '15min') {
            $period = (int) $row['period'];
            $hour = (int) (($period - 1) / 4);  // 1-4=hod 0, 5-8=hod 1, ...
            $blockShare = 0.25;  // 1/4 hodiny
        } else {
            $hour = (int) $row['hour'];
            $blockShare = 1.0;
        }

        // Spotřeba v tomto bloku:
        // hodinová_spotřeba = (TDD_koef[hour] / suma_dnů_v_měsíci) × měsíční_spotřeba
        // 15min_spotřeba = hodinová / 4
        $isNt = in_array($hour, $ntHours, true);
        if ($useUserSplit) {
            $monthlyForThisBand = $isNt ? $kwhNt : $kwhVt;
            $coefForThisBand = $isNt ? $coefNt : $coefVt;
            $hourKwh = $coefForThisBand > 0
                ? ($hourProfile[$hour] / $coefForThisBand) * $monthlyForThisBand / $daysInMonth
                : 0;
        } else {
            // Bez rozdělení - všechno jako VT, distribuce se ale počítá zvlášť VT/NT
            $hourKwh = $hourProfile[$hour] * $totalKwh / $daysInMonth;
        }
        $blockKwh = $hourKwh * $blockShare;

        // Cena = (spot + poplatek_obchodu) × spotřeba
        $silovaPriceMwh = $priceCzk + $tradeFee;
        $silovaBlock = $silovaPriceMwh * $blockKwh / 1000;
        $silovaCelkem += $silovaBlock;

        if ($priceCzk < $minPrice) $minPrice = $priceCzk;
        if ($priceCzk > $maxPrice) $maxPrice = $priceCzk;
        if ($priceCzk < 0) $negKwh += $blockKwh;

        $kwhSpotreba[$day][$hour] = ($kwhSpotreba[$day][$hour] ?? 0) + $blockKwh;
    }

    // ─── Distribuce + ostatní (nezávislé na hodinové ceně) ───
    $distribVtKc = $distribVt * $kwhVt / 1000;
    $distribNtKc = $distribNt * $kwhNt / 1000;
    $danKc = $danElektrina * $totalKwh / 1000;
    $sysKc = $sysSluzby * $totalKwh / 1000;

    // POZE - počítá se nižší (kWh nebo per A)
    $pozeKwhKc = $pozeKwh * $totalKwh / 1000;
    $pozeAKc = $pozeA * $jisticA * $jisticPh;  // Kč/měsíc
    $pozeFinal = min($pozeKwhKc, $pozeAKc);
    $pozeMode = $pozeKwhKc < $pozeAKc ? 'kwh' : 'jistic';

    // Stálé platby
    $monthlyKc = $monthlyFee + $jisticFee + $infraNesit;

    // Celkem
    $celkem = $silovaCelkem + $distribVtKc + $distribNtKc + $danKc + $sysKc + $pozeFinal + $monthlyKc;
    $avgPriceKwh = $totalKwh > 0 ? round($celkem / $totalKwh, 2) : 0;
    $avgSpotKwh = $totalKwh > 0 ? round(1000 * $silovaCelkem / $totalKwh, 0) : 0;

    return [
        'period'        => sprintf('%04d-%02d', $year, $month),
        'days_in_month' => $daysInMonth,
        'granularity'   => $useGranularity,
        'price_rows'    => count($rawPrices),
        'inputs' => [
            'tdd' => $tdd, 'tariff' => $tariff,
            'kwh_vt' => $kwhVt, 'kwh_nt' => $kwhNt, 'total_kwh' => $totalKwh,
            'jistic' => "{$jisticPh}×{$jisticA}A",
        ],
        'breakdown' => [
            'silova_kc'    => round($silovaCelkem, 2),
            'distrib_vt_kc' => round($distribVtKc, 2),
            'distrib_nt_kc' => round($distribNtKc, 2),
            'dan_kc'       => round($danKc, 2),
            'sys_kc'       => round($sysKc, 2),
            'poze_kc'      => round($pozeFinal, 2),
            'poze_mode'    => $pozeMode,
            'monthly_kc'   => round($monthlyKc, 2),
            'celkem_kc'    => round($celkem, 2),
        ],
        'stats' => [
            'avg_price_kwh' => $avgPriceKwh,        // Kč/kWh kompletní
            'avg_spot_kwh'  => $avgSpotKwh / 1000,  // Kč/kWh jen silová
            'min_spot_mwh'  => round($minPrice, 2),
            'max_spot_mwh'  => round($maxPrice, 2),
            'kwh_negative'  => round($negKwh, 2),
        ],
        'generated_at' => date('c'),
    ];
}

/**
 * Zjednodušené TDD profily - hodinové koeficienty 0-23, suma = 1.0
 * Reálné OTE TDD jsou normalizované podle teploty + den v týdnu, tady použito pr~ %, jednoduše.
 */
function getTddProfile(): array
{
    return [
        // TDD4 = bez vytápění, malý odběr (D01d, D02d - klasická domácnost)
        4 => [
            0.025, 0.020, 0.018, 0.018, 0.020, 0.025,  // 0-5 noc
            0.035, 0.050, 0.055, 0.050, 0.045, 0.045,  // 6-11 ráno + dop.
            0.045, 0.040, 0.040, 0.045, 0.055, 0.070,  // 12-17 odp.
            0.080, 0.075, 0.060, 0.050, 0.040, 0.030,  // 18-23 večer
        ],
        // TDD5 = malý odběr 2-tarifní
        5 => [
            0.030, 0.025, 0.022, 0.022, 0.025, 0.030,
            0.040, 0.055, 0.055, 0.045, 0.040, 0.040,
            0.040, 0.035, 0.035, 0.040, 0.050, 0.065,
            0.075, 0.070, 0.060, 0.050, 0.040, 0.032,
        ],
        // TDD6 = akumulační vytápění (D25d, D26d) - peak v noci
        6 => [
            0.090, 0.090, 0.090, 0.090, 0.090, 0.060,  // noc - akumulace
            0.030, 0.030, 0.025, 0.020, 0.018, 0.015,
            0.015, 0.015, 0.015, 0.018, 0.020, 0.030,
            0.035, 0.035, 0.030, 0.025, 0.020, 0.014,
        ],
        // TDD7 = smíšené vytápění (D35d, D45d, D56d)
        7 => [
            0.060, 0.055, 0.050, 0.050, 0.050, 0.045,
            0.040, 0.045, 0.045, 0.040, 0.035, 0.035,
            0.035, 0.035, 0.035, 0.040, 0.045, 0.055,
            0.060, 0.055, 0.050, 0.045, 0.040, 0.025,
        ],
        // TDD8 = přímotopné vytápění (D45d, D57d) - peak ráno + večer
        8 => [
            0.045, 0.045, 0.045, 0.045, 0.050, 0.055,
            0.060, 0.065, 0.060, 0.045, 0.035, 0.035,
            0.035, 0.035, 0.035, 0.040, 0.050, 0.060,
            0.065, 0.060, 0.055, 0.050, 0.045, 0.035,
        ],
    ];
}

/**
 * NT (nízký tarif) hodiny pro různé distribuční sazby - typický průběh
 * D02d (jeden tarif) = []
 * D25d (8h NT v noci) = 22-05
 * D26d (8h NT pružný) = 22-05
 * D45d (přímotop 16h NT) = většina dne
 * D57d (elektr. topení 20h NT) = skoro vždy
 */
function getTariffNtHours(string $tariff): array
{
    return match (strtoupper($tariff)) {
        'D02D'        => [],  // jednotarif
        'D25D', 'D26D' => [22, 23, 0, 1, 2, 3, 4, 5],  // 8h v noci
        'D27D'        => [0, 1, 2, 3, 4, 5, 6, 21, 22, 23],  // elektromobilita
        'D45D'        => [0, 1, 2, 3, 4, 5, 6, 7, 8, 13, 14, 15, 19, 20, 21, 22, 23],  // 16h
        'D56D', 'D35D' => [0, 1, 2, 3, 4, 5, 6, 13, 14, 15, 16, 21, 22, 23],
        'D57D'        => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23],  // 20h
        'D61D'        => [],  // víkend - speciální (zde zjednodušení)
        default       => [],
    };
}
