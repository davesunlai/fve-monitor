<?php
/**
 * Cron: Rozešle push notifikace pro nové alerty.
 *
 * Strategie: každý alert má sloupec 'push_sent_at'. Pokud je NULL a severity
 * je warn/critical, pošleme notifikaci a označíme push_sent_at = NOW().
 *
 * Crontab:
 *   *\/5 * * * * php /var/www/sunlai.org/fve/cron/send_push.php >> /var/www/sunlai.org/fve/logs/push.log 2>&1
 */

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Database;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$config = require __DIR__ . '/../config/config.php';
$vapid  = $config['vapid'] ?? null;
if (!$vapid) {
    echo "[" . date('Y-m-d H:i:s') . "] VAPID není nakonfigurováno\n";
    exit(1);
}

// Najdi alerty které ještě nebyly poslány
$alerts = Database::all(
    "SELECT a.*, p.name AS plant_name, p.code AS plant_code
     FROM alerts a
     JOIN plants p ON p.id = a.plant_id
     WHERE a.push_sent_at IS NULL
       AND a.acknowledged_at IS NULL
       AND a.severity IN ('warning', 'critical')
     ORDER BY a.created_at ASC
     LIMIT 50"
);

if (empty($alerts)) {
    // Žádné nové alerty k rozeslání - ticho
    exit(0);
}

// Načti všechny aktivní subscriptions
$subs = Database::all('SELECT * FROM push_subscriptions ORDER BY id');
if (empty($subs)) {
    echo "[" . date('Y-m-d H:i:s') . "] Žádné subscriptions v DB\n";
    exit(0);
}

// Init WebPush
$webPush = new WebPush([
    'VAPID' => [
        'subject'    => $vapid['subject'],
        'publicKey'  => $vapid['public_key'],
        'privateKey' => $vapid['private_key'],
    ],
]);

$ts = date('Y-m-d H:i:s');

// Pro každý alert → pošli všem subscriptions
foreach ($alerts as $alert) {
    $icon = match($alert['severity']) {
        'critical' => '🚨',
        'warning'  => '⚠️',
        default    => 'ℹ️',
    };

    $payload = json_encode([
        'title' => $icon . ' ' . $alert['plant_name'],
        'body'  => $alert['message'],
        'icon'  => '/assets/icon-192.png',
        'badge' => '/assets/icon-192.png',
        'url'   => '/?plant=' . $alert['plant_id'],
        'tag'   => 'alert-' . $alert['id'],
    ]);

    foreach ($subs as $sub) {
        $subscription = Subscription::create([
            'endpoint'        => $sub['endpoint'],
            'publicKey'       => $sub['p256dh_key'],
            'authToken'       => $sub['auth_key'],
            'contentEncoding' => 'aes128gcm',
        ]);
        $webPush->queueNotification($subscription, $payload);
    }

    // Označ jako odeslaný
    Database::pdo()->prepare(
        'UPDATE alerts SET push_sent_at = NOW() WHERE id = ?'
    )->execute([$alert['id']]);
}

// Pošli všechny nashromážděné notifikace
$stats = ['sent' => 0, 'failed' => 0, 'gone' => 0];
foreach ($webPush->flush() as $report) {
    if ($report->isSuccess()) {
        $stats['sent']++;
    } else {
        $stats['failed']++;
        // HTTP 410 Gone / 404 → subscription neexistuje → smaž z DB
        $statusCode = $report->getResponse()?->getStatusCode() ?? 0;
        if (in_array($statusCode, [404, 410])) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            Database::pdo()->prepare(
                'DELETE FROM push_subscriptions WHERE endpoint = ?'
            )->execute([$endpoint]);
            $stats['gone']++;
        }
    }
}

echo "[$ts] Alerty: " . count($alerts) . " | Push odesláno: {$stats['sent']}, selhalo: {$stats['failed']}, zastaralých smazáno: {$stats['gone']}\n";
