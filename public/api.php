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
        'distribution_list'       => actionDistributionList($_GET['from'] ?? null, $_GET['to'] ?? null),
        'distribution_save'       => actionDistributionSave(),
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Fallback na WeatherAPI při selhání Open-Meteo
    if ($raw === false || $raw === '' || $httpCode !== 200) {
        $fallbackForecast = tryWeatherApiHourly($lat, $lon, $kwp, (int)$tilt, (int)$azimuth, $loss);
        if ($fallbackForecast !== null) {
            // Použij fallback a vrať s PVGIS profilem
            $month = (int) date('n');
            $pvgisRow = Database::one(
                'SELECT SUM(e_m_kwh) as energy_kwh FROM pvgis_monthly WHERE plant_id = ? AND month = ?',
                [$plantId, $month]
            );
            $pvgisMonthKwh = (float) ($pvgisRow['energy_kwh'] ?? 0);
            $daysInMonth  = (int) date('t');
            $pvgisDayKwh  = $pvgisMonthKwh / $daysInMonth;

            $pvgisProfile = [];
            $startDate = new \DateTime('today midnight');
            for ($d = 0; $d < 4; $d++) {
                $day = clone $startDate;
                $day->modify("+{$d} days");
                $scale = $pvgisDayKwh > 0 ? $pvgisDayKwh / 7.5 : 0;
                for ($h = 0; $h < 24; $h++) {
                    $kw = 0;
                    if ($h >= 6 && $h <= 20) {
                        $pos = ($h - 6) / 14.0;
                        $kw  = round($scale * sin(M_PI * $pos), 3);
                    }
                    $ts = $day->format('Y-m-d') . ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00:00';
                    $pvgisProfile[] = ['ts' => $ts, 'power_kw' => max(0, $kw)];
                }
            }

            return [
                'plant_id'       => $plantId,
                'forecast'       => $fallbackForecast,
                'pvgis_profile'  => $pvgisProfile,
                'pvgis_day_kwh'  => round($pvgisDayKwh, 2),
                'generated_at'   => date('c'),
                'source'         => 'weatherapi_fallback',
            ];
        }
        throw new \RuntimeException('Open-Meteo API nedostupné: ' . $err);
    }

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

    // Cache check - pokud je <6 hodin staré, vrátit z DB bez API volání
    $cacheAge = Database::one(
        "SELECT TIMESTAMPDIFF(MINUTE, MAX(fetched_at), NOW()) AS age_min FROM weather_forecast_cache"
    );
    $cacheFresh = ($cacheAge && $cacheAge['age_min'] !== null && (int)$cacheAge['age_min'] < 360);

    if ($cacheFresh) {
        $rows = Database::all(
            "SELECT plant_id, forecast_date, weather_code, tmax_c, est_kwh
             FROM weather_forecast_cache
             WHERE forecast_date >= CURDATE()
             ORDER BY plant_id, forecast_date"
        );
        foreach ($rows as $r) {
            $result[(int)$r['plant_id']][] = [
                'date'         => $r['forecast_date'],
                'weather_code' => (int)$r['weather_code'],
                'tmax'         => (int)$r['tmax_c'],
                'est_kwh'      => (int)$r['est_kwh'],
            ];
        }
        return [
            'plants' => $result,
            'generated_at' => date('c'),
            'from_cache' => true,
            'cache_age_min' => (int)$cacheAge['age_min'],
        ];
    }

    // Zavolat Open-Meteo
    $apiFailed = 0;
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
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $httpCode !== 200) {
            // Open-Meteo selhal - zkus WeatherAPI.com jako fallback
            $fallbackDays = tryWeatherApi($lat, $lon, $kwp);
            if ($fallbackDays !== null) {
                foreach ($fallbackDays as $d) {
                    Database::pdo()->prepare(
                        "INSERT INTO weather_forecast_cache
                            (plant_id, forecast_date, weather_code, tmax_c, est_kwh)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            weather_code = VALUES(weather_code),
                            tmax_c = VALUES(tmax_c),
                            est_kwh = VALUES(est_kwh),
                            fetched_at = NOW()"
                    )->execute([(int)$p['id'], $d['date'], $d['weather_code'], $d['tmax'], $d['est_kwh']]);
                }
                $result[(int)$p['id']] = $fallbackDays;
            } else {
                $apiFailed++;
            }
            continue;
        }

        $data = json_decode($raw, true);
        $dates = $data['daily']['time'] ?? [];
        $codes = $data['daily']['weather_code'] ?? [];
        $temps = $data['daily']['temperature_2m_max'] ?? [];
        $rads  = $data['daily']['shortwave_radiation_sum'] ?? [];

        $days = [];
        foreach ($dates as $i => $d) {
            $rad = (float)($rads[$i] ?? 0);
            $estKwh = (int)round($rad * $kwp * 0.8 / 3.6, 0);
            $code = (int)($codes[$i] ?? 0);
            $tmax = (int)round((float)($temps[$i] ?? 0));

            $days[] = [
                'date'         => $d,
                'weather_code' => $code,
                'tmax'         => $tmax,
                'est_kwh'      => $estKwh,
            ];

            Database::pdo()->prepare(
                "INSERT INTO weather_forecast_cache
                    (plant_id, forecast_date, weather_code, tmax_c, est_kwh)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    weather_code = VALUES(weather_code),
                    tmax_c = VALUES(tmax_c),
                    est_kwh = VALUES(est_kwh),
                    fetched_at = NOW()"
            )->execute([(int)$p['id'], $d, $code, $tmax, $estKwh]);
        }
        $result[(int)$p['id']] = $days;
    }

    // Pokud API selhalo celkově, použij starou cache jako fallback
    if (empty($result)) {
        $rows = Database::all(
            "SELECT plant_id, forecast_date, weather_code, tmax_c, est_kwh
             FROM weather_forecast_cache
             WHERE forecast_date >= CURDATE()
             ORDER BY plant_id, forecast_date"
        );
        foreach ($rows as $r) {
            $result[(int)$r['plant_id']][] = [
                'date'         => $r['forecast_date'],
                'weather_code' => (int)$r['weather_code'],
                'tmax'         => (int)$r['tmax_c'],
                'est_kwh'      => (int)$r['est_kwh'],
            ];
        }
        return [
            'plants' => $result,
            'generated_at' => date('c'),
            'from_cache' => true,
            'api_failed' => $apiFailed,
        ];
    }

    return [
        'plants' => $result,
        'generated_at' => date('c'),
        'from_cache' => false,
        'api_failed' => $apiFailed,
    ];
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

    // ─── DT 15min predikce z denní aukce ───
    if ($granularity === 'dt15min') {
        return actionSpotPricesDt15min($from, $to, $day, $isDate);
    }

    // ─── Compare: DT predikce vs VDT realita ───
    if ($granularity === 'compare') {
        return actionSpotPricesCompare($from, $to, $day, $isDate);
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

/**
 * DT 15min predikce - z denní aukce, publikováno D-1 v 14:00
 *   ?action=spot_prices&granularity=dt15min                    — dnes + zítra + pozítří
 *   ?action=spot_prices&granularity=dt15min&day=2026-05-09     — konkrétní den
 *   ?action=spot_prices&granularity=dt15min&from=A&to=B        — rozsah
 */
function actionSpotPricesDt15min(?string $from, ?string $to, ?string $day, callable $isDate): array
{
    if ($isDate($day)) {
        $rows = \FveMonitor\Lib\Database::all(
            "SELECT delivery_day, period, time_from,
                    price_15min_eur, price_60min_eur,
                    price_15min_czk, price_60min_czk,
                    volume_mwh, saldo_mwh, eur_czk_rate
             FROM spot_prices_dt15min WHERE delivery_day = ? ORDER BY period",
            [$day]
        );
        return [
            'mode'         => 'day_dt15min',
            'granularity'  => 'dt15min',
            'day'          => $day,
            'periods'      => $rows,
            'stats'        => spotDt15Stats($rows),
            'generated_at' => date('c'),
        ];
    }

    if ($isDate($from) && $isDate($to)) {
        $daily = \FveMonitor\Lib\Database::all(
            "SELECT delivery_day,
                    ROUND(MIN(price_15min_eur),2) AS min_eur,
                    ROUND(AVG(price_15min_eur),2) AS avg_eur,
                    ROUND(MAX(price_15min_eur),2) AS max_eur,
                    ROUND(MIN(price_15min_czk),2) AS min_czk,
                    ROUND(AVG(price_15min_czk),2) AS avg_czk,
                    ROUND(MAX(price_15min_czk),2) AS max_czk,
                    SUM(CASE WHEN price_15min_eur < 0 THEN 1 ELSE 0 END) AS negative_periods,
                    COUNT(*) AS periods
             FROM spot_prices_dt15min
             WHERE delivery_day BETWEEN ? AND ?
             GROUP BY delivery_day
             ORDER BY delivery_day",
            [$from, $to]
        );
        $all = \FveMonitor\Lib\Database::all(
            "SELECT price_15min_eur AS p_eur, price_15min_czk AS p_czk
             FROM spot_prices_dt15min WHERE delivery_day BETWEEN ? AND ?",
            [$from, $to]
        );
        return [
            'mode'         => 'range_dt15min',
            'granularity'  => 'dt15min',
            'from'         => $from,
            'to'           => $to,
            'days'         => $daily,
            'stats'        => spotDt15Stats($all, 'p_eur', 'p_czk'),
            'generated_at' => date('c'),
        ];
    }

    // Default: dnes + zítra + pozítří
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $dayAfter = date('Y-m-d', strtotime('+2 days'));

    $rows = \FveMonitor\Lib\Database::all(
        "SELECT delivery_day, period, time_from,
                price_15min_eur, price_60min_eur,
                price_15min_czk, price_60min_czk,
                volume_mwh, saldo_mwh, eur_czk_rate
         FROM spot_prices_dt15min WHERE delivery_day IN (?, ?, ?) ORDER BY delivery_day, period",
        [$today, $tomorrow, $dayAfter]
    );
    $byDay = ['today' => [], 'tomorrow' => [], 'day_after' => []];
    foreach ($rows as $r) {
        if ($r['delivery_day'] === $today) $byDay['today'][] = $r;
        elseif ($r['delivery_day'] === $tomorrow) $byDay['tomorrow'][] = $r;
        else $byDay['day_after'][] = $r;
    }

    return [
        'mode'                => 'multi_day_dt15min',
        'granularity'         => 'dt15min',
        'today_date'          => $today,
        'tomorrow_date'       => $tomorrow,
        'day_after_date'      => $dayAfter,
        'today'               => $byDay['today'],
        'tomorrow'            => $byDay['tomorrow'],
        'day_after'           => $byDay['day_after'],
        'today_stats'         => spotDt15Stats($byDay['today']),
        'tomorrow_stats'      => spotDt15Stats($byDay['tomorrow']),
        'day_after_stats'     => spotDt15Stats($byDay['day_after']),
        'tomorrow_available'  => count($byDay['tomorrow']) > 0,
        'day_after_available' => count($byDay['day_after']) > 0,
        'generated_at'        => date('c'),
    ];
}

/**
 * Compare: DT 15min predikce vs VDT 15min realita - per period
 *   ?action=spot_prices&granularity=compare&day=2026-05-08
 *   Výchozí = dnešek
 */
function actionSpotPricesCompare(?string $from, ?string $to, ?string $day, callable $isDate): array
{
    if (!$isDate($day)) {
        $day = date('Y-m-d');
    }

    $rows = \FveMonitor\Lib\Database::all(
        "SELECT
            dt.period, dt.time_from,
            dt.price_15min_eur AS dt_eur,
            dt.price_15min_czk AS dt_czk,
            vdt.price_avg_eur AS vdt_eur,
            vdt.price_avg_czk AS vdt_czk,
            vdt.volume_mwh AS vdt_volume,
            ROUND(vdt.price_avg_eur - dt.price_15min_eur, 2) AS diff_eur,
            ROUND(vdt.price_avg_czk - dt.price_15min_czk, 2) AS diff_czk,
            CASE
              WHEN dt.price_15min_eur IS NOT NULL AND vdt.price_avg_eur IS NOT NULL AND dt.price_15min_eur != 0
              THEN ROUND(100 * (vdt.price_avg_eur - dt.price_15min_eur) / dt.price_15min_eur, 1)
              ELSE NULL
            END AS diff_pct
         FROM spot_prices_dt15min dt
         LEFT JOIN spot_prices_15min vdt
           ON vdt.delivery_day = dt.delivery_day AND vdt.period = dt.period
         WHERE dt.delivery_day = ?
         ORDER BY dt.period",
        [$day]
    );

    // Statistiky srovnání
    $diffs = array_filter(
        array_map(fn($r) => $r['diff_eur'] !== null ? (float)$r['diff_eur'] : null, $rows),
        fn($v) => $v !== null
    );
    $diffPcts = array_filter(
        array_map(fn($r) => $r['diff_pct'] !== null ? (float)$r['diff_pct'] : null, $rows),
        fn($v) => $v !== null
    );

    $stats = [
        'count'          => count($rows),
        'periods_with_data' => count($diffs),
        'avg_diff_eur'   => $diffs ? round(array_sum($diffs) / count($diffs), 2) : null,
        'max_over_eur'   => $diffs ? round(max($diffs), 2) : null,
        'max_under_eur'  => $diffs ? round(min($diffs), 2) : null,
        'avg_diff_pct'   => $diffPcts ? round(array_sum($diffPcts) / count($diffPcts), 1) : null,
        'periods_over'   => count(array_filter($diffs, fn($v) => $v > 0)),
        'periods_under'  => count(array_filter($diffs, fn($v) => $v < 0)),
    ];

    return [
        'mode'         => 'compare',
        'granularity'  => 'compare',
        'day'          => $day,
        'periods'      => $rows,
        'stats'        => $stats,
        'generated_at' => date('c'),
    ];
}

function spotDt15Stats(array $rows, string $eurKey = 'price_15min_eur', string $czkKey = 'price_15min_czk'): array
{
    if (empty($rows)) {
        return ['count' => 0, 'min_eur' => null, 'avg_eur' => null, 'max_eur' => null,
                'min_czk' => null, 'avg_czk' => null, 'max_czk' => null,
                'negative_periods' => 0];
    }
    $eur = array_filter(array_map(fn($r) => $r[$eurKey] !== null ? (float) $r[$eurKey] : null, $rows), fn($v) => $v !== null);
    $czk = array_filter(array_map(fn($r) => $r[$czkKey] !== null ? (float) $r[$czkKey] : null, $rows), fn($v) => $v !== null);
    if (empty($eur)) {
        return ['count' => count($rows), 'min_eur' => null, 'avg_eur' => null, 'max_eur' => null,
                'min_czk' => null, 'avg_czk' => null, 'max_czk' => null, 'negative_periods' => 0];
    }
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
 * GET ?action=distribution_list&from=YYYY-MM&to=YYYY-MM
 *
 * Vrací matrix data pro admin/distribution_data.php:
 * - seznam aktivních FVE s ote_id (jen ty, které se reportují do POZE)
 * - existující hodnoty grid_export_kwh / grid_import_kwh za zadané období
 *
 * Default rozsah: posledních 12 měsíců (od dnes - 11 měsíců až dnes).
 *
 * Návratová struktura:
 *   {
 *     "months": ["2025-06", "2025-07", ...],     // chronologicky
 *     "plants": [
 *       {
 *         "id": 3,
 *         "code": "ZLIN-01-CZ",
 *         "name": "Monkstone Zlín",
 *         "ote_id": "043786_Z11",
 *         "data": {
 *           "2025-09": {"export": 1159, "import": 71827, "invoice_ref": null, "notes": null},
 *           "2025-10": null
 *         }
 *       }, ...
 *     ]
 *   }
 */
function actionDistributionList(?string $from, ?string $to): array
{
    // Default: posledních 12 měsíců
    if ($from === null || !preg_match('/^\d{4}-\d{2}$/', $from)) {
        $from = date('Y-m', strtotime('-11 months'));
    }
    if ($to === null || !preg_match('/^\d{4}-\d{2}$/', $to)) {
        $to = date('Y-m');
    }

    // Vygeneruj seznam měsíců [from, to]
    $months = [];
    $cur = strtotime($from . '-01');
    $end = strtotime($to . '-01');
    while ($cur <= $end) {
        $months[] = date('Y-m', $cur);
        $cur = strtotime('+1 month', $cur);
    }

    $pdo = Database::pdo();

    // FVE, které se reportují do POZE (mají ote_id)
    $plants = $pdo->query(
        "SELECT id, code, name, ote_id, ote_vyrobna_id, peak_power_kwp
         FROM plants
         WHERE is_active = 1 AND ote_id IS NOT NULL AND ote_id <> ''
         ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Načti všechna existující data pro tyto FVE v rozsahu
    if (count($plants) > 0) {
        $plantIds = array_column($plants, 'id');
        $placeholders = implode(',', array_fill(0, count($plantIds), '?'));
        $sql = "SELECT plant_id, `year_month`, grid_export_kwh, grid_import_kwh, invoice_ref, notes, updated_at
                FROM distribution_monthly
                WHERE plant_id IN ($placeholders)
                  AND `year_month` BETWEEN ? AND ?
                ORDER BY plant_id, `year_month`";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($plantIds, [$from, $to]));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } else {
        $rows = [];
    }

    // Indexuj data: [plant_id][year_month] => row
    $byPlant = [];
    foreach ($rows as $r) {
        $byPlant[(int) $r['plant_id']][$r['year_month']] = [
            'export'      => $r['grid_export_kwh'] !== null ? (float) $r['grid_export_kwh'] : null,
            'import'      => $r['grid_import_kwh'] !== null ? (float) $r['grid_import_kwh'] : null,
            'invoice_ref' => $r['invoice_ref'],
            'notes'       => $r['notes'],
            'updated_at'  => $r['updated_at'],
        ];
    }

    // Sestav výstup s kompletní mřížkou (NULL pro chybějící buňky)
    $out = [];
    foreach ($plants as $p) {
        $data = [];
        foreach ($months as $m) {
            $data[$m] = $byPlant[(int) $p['id']][$m] ?? null;
        }
        $out[] = [
            'id'             => (int) $p['id'],
            'code'           => $p['code'],
            'name'           => $p['name'],
            'ote_id'         => $p['ote_id'],
            'ote_vyrobna_id' => $p['ote_vyrobna_id'],
            'peak_power_kwp' => (float) $p['peak_power_kwp'],
            'data'           => $data,
        ];
    }

    return [
        'from'   => $from,
        'to'     => $to,
        'months' => $months,
        'plants' => $out,
    ];
}

/**
 * POST ?action=distribution_save
 *
 * Body (JSON):
 *   {
 *     "plant_id": 3,
 *     "year_month": "2025-09",
 *     "field": "export" | "import",       // nebo "invoice_ref" / "notes"
 *     "value": 1159.5                      // nebo null pro vymazání
 *   }
 *
 * Upsert do distribution_monthly. Vyžaduje přihlášení.
 */
function actionDistributionSave(): array
{
    if (!\FveMonitor\Lib\Auth::isLoggedIn()) {
        http_response_code(401);
        return ['error' => 'Vyžaduje přihlášení'];
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['error' => 'POST only'];
    }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        return ['error' => 'Invalid JSON'];
    }

    $plantId   = (int) ($body['plant_id'] ?? 0);
    $yearMonth = (string) ($body['year_month'] ?? '');
    $field     = (string) ($body['field'] ?? '');
    $value     = $body['value'] ?? null;

    if ($plantId <= 0)                                       { http_response_code(400); return ['error' => 'plant_id']; }
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth))          { http_response_code(400); return ['error' => 'year_month formát YYYY-MM']; }
    if (!in_array($field, ['export', 'import', 'invoice_ref', 'notes'], true)) {
        http_response_code(400);
        return ['error' => 'field musí být export/import/invoice_ref/notes'];
    }

    // Mapování field → DB sloupec
    $colMap = [
        'export'      => 'grid_export_kwh',
        'import'      => 'grid_import_kwh',
        'invoice_ref' => 'invoice_ref',
        'notes'       => 'notes',
    ];
    $col = $colMap[$field];

    // Validace hodnoty podle typu
    if ($field === 'export' || $field === 'import') {
        if ($value !== null && $value !== '') {
            if (!is_numeric($value) || (float) $value < 0) {
                http_response_code(400);
                return ['error' => 'Hodnota musí být nezáporné číslo'];
            }
            $value = (float) $value;
        } else {
            $value = null;
        }
    } else {
        // invoice_ref, notes — string nebo null
        if ($value === '') $value = null;
        if ($value !== null) $value = (string) $value;
    }

    $pdo = Database::pdo();

    // Ověř že plant existuje a je aktivní s ote_id
    $stmt = $pdo->prepare("SELECT id FROM plants WHERE id = ? AND is_active = 1 AND ote_id IS NOT NULL AND ote_id <> ''");
    $stmt->execute([$plantId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        return ['error' => 'FVE nenalezena nebo nemá OTE_ID'];
    }

    // Upsert
    $sql = "INSERT INTO distribution_monthly (plant_id, `year_month`, $col, source)
            VALUES (?, ?, ?, 'manual')
            ON DUPLICATE KEY UPDATE $col = VALUES($col), source = 'manual'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$plantId, $yearMonth, $value]);

    // Vrať aktualizovaný řádek
    $stmt = $pdo->prepare(
        "SELECT grid_export_kwh, grid_import_kwh, invoice_ref, notes, updated_at
         FROM distribution_monthly WHERE plant_id = ? AND `year_month` = ?"
    );
    $stmt->execute([$plantId, $yearMonth]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    return [
        'ok'         => true,
        'plant_id'   => $plantId,
        'year_month' => $yearMonth,
        'export'     => $row['grid_export_kwh'] !== null ? (float) $row['grid_export_kwh'] : null,
        'import'     => $row['grid_import_kwh'] !== null ? (float) $row['grid_import_kwh'] : null,
        'invoice_ref'=> $row['invoice_ref'] ?? null,
        'notes'      => $row['notes'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}


/**
 * Helper: zavolá WeatherAPI.com pro hodinovou předpověď (4 dny).
 * Vrátí array [{ts, power_kw}] nebo null při selhání.
 */
function tryWeatherApiHourly(float $lat, float $lon, float $kwp, int $tilt, int $azimuth, float $loss): ?array
{
    $keyFile = __DIR__ . '/../config/weather.local.php';
    if (!file_exists($keyFile)) return null;
    $cfg = require $keyFile;
    $key = $cfg['weatherapi_key'] ?? '';
    if (!$key) return null;

    $url = "https://api.weatherapi.com/v1/forecast.json?"
         . "key={$key}&q={$lat},{$lon}&days=4&aqi=no&alerts=no";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $httpCode !== 200) return null;
    $data = json_decode($raw, true);
    $days = $data['forecast']['forecastday'] ?? [];
    if (empty($days)) return null;

    $pr = 0.80;
    $forecast = [];

    foreach ($days as $day) {
        $hours = $day['hour'] ?? [];
        foreach ($hours as $h) {
            $time = $h['time'] ?? null; // "2026-06-04 14:00"
            if (!$time) continue;
            $cloudPct = (float)($h['cloud'] ?? 50); // 0-100 %
            $hour = (int)substr($time, 11, 2);

            // Aproximace tilted irradiance z hodinového vzorce + cloud cover
            // Solar elevation pro Českou republiku ~50°N v létě
            // Max irradiance ve 12-13h: ~900 W/m² při jasné obloze
            $maxGti = 900;
            $solarHour = $hour - 12; // -12..+12
            $elevationFactor = max(0, cos(deg2rad($solarHour * 15))); // hrubý odhad
            $cloudFactor = 1 - ($cloudPct / 100) * 0.75; // i v mracích něco prochází
            $gti = $maxGti * $elevationFactor * $cloudFactor;

            // Korekce na tilt: panely na fixním sklonu jsou v zimě efektivnější
            // (zanedbáme, je to jen odhad)

            $kw = max(0, round($gti / 1000 * $kwp * $pr * $loss, 3));
            $tsFormatted = $time . ':00';
            $forecast[] = ['ts' => $tsFormatted, 'power_kw' => $kw];
        }
    }

    return empty($forecast) ? null : $forecast;
}

/**
 * Helper: zavolá WeatherAPI.com pro 1 FVE, vrátí dny nebo null při selhání.
 */
function tryWeatherApi(float $lat, float $lon, float $kwp): ?array
{
    // Načti API key z lokálního konfigu (mimo git)
    $keyFile = __DIR__ . '/../config/weather.local.php';
    if (!file_exists($keyFile)) return null;
    $cfg = require $keyFile;
    $key = $cfg['weatherapi_key'] ?? '';
    if (!$key) return null;

    $url = "https://api.weatherapi.com/v1/forecast.json?"
         . "key={$key}&q={$lat},{$lon}&days=3&aqi=no&alerts=no";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $httpCode !== 200) return null;

    $data = json_decode($raw, true);
    $forecastDays = $data['forecast']['forecastday'] ?? [];
    if (empty($forecastDays)) return null;

    // Mapování WeatherAPI condition.code → WMO weather_code (Open-Meteo formát)
    // WeatherAPI používá vlastní kódy 1000-1282, my chceme WMO 0-99
    $mapToWmo = function(int $code): int {
        // Zjednodušené mapování - nejdůležitější kódy
        $map = [
            1000 => 0,   // Sunny / Clear
            1003 => 1,   // Partly cloudy
            1006 => 2,   // Cloudy
            1009 => 3,   // Overcast
            1030 => 45,  // Mist
            1063 => 61,  // Patchy rain
            1066 => 71,  // Patchy snow
            1069 => 67,  // Patchy sleet
            1087 => 95,  // Thundery
            1135 => 45,  // Fog
            1147 => 48,  // Freezing fog
            1150 => 51,  // Light drizzle patchy
            1153 => 53,  // Light drizzle
            1180 => 61,  // Patchy light rain
            1183 => 61,  // Light rain
            1186 => 63,  // Moderate rain at times
            1189 => 63,  // Moderate rain
            1192 => 65,  // Heavy rain at times
            1195 => 65,  // Heavy rain
            1210 => 71,  // Patchy light snow
            1213 => 71,  // Light snow
            1216 => 73,  // Moderate snow at times
            1219 => 73,  // Moderate snow
            1222 => 75,  // Heavy snow at times
            1225 => 75,  // Heavy snow
            1273 => 95,  // Patchy light rain with thunder
            1276 => 95,  // Moderate or heavy rain with thunder
        ];
        return $map[$code] ?? 1;
    };

    $days = [];
    foreach ($forecastDays as $fd) {
        $date = $fd['date'] ?? null;
        if (!$date) continue;
        $day = $fd['day'] ?? [];

        // WeatherAPI nedává radiation - aproximujeme z slunečních hodin a UV
        // Lépe: použijeme totalprecip a uv index
        $tmax = (int)round((float)($day['maxtemp_c'] ?? 0));
        $uv   = (float)($day['uv'] ?? 0);
        $cloudCover = (float)($day['daily_chance_of_rain'] ?? 50); // 0-100
        $code = (int)($day['condition']['code'] ?? 1000);

        // Odhad denní radiace pro Českou republiku v dubnu-červnu:
        // - jasný den: ~20 MJ/m² (5.5 kWh/m²)
        // - zatažený den: ~5 MJ/m² (1.4 kWh/m²)
        // Použijeme UV index jako proxy pro radiaci (UV vs cloud cover)
        $clearSkyRad = 20.0; // MJ/m² jasný den
        $cloudFactor = 1 - ($cloudCover / 100) * 0.7; // 30% i v dešti
        $estRad = $clearSkyRad * $cloudFactor;
        $estKwh = (int)round($estRad * $kwp * 0.8 / 3.6, 0);

        $days[] = [
            'date'         => $date,
            'weather_code' => $mapToWmo($code),
            'tmax'         => $tmax,
            'est_kwh'      => $estKwh,
        ];
    }

    return empty($days) ? null : $days;
}
