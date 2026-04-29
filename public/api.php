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
