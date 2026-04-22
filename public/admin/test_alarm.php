<?php
/**
 * Admin: vytvoří testovací alarm + okamžitě rozešle push notifikace.
 * Parametry: ?plant_id=N&severity=warning|critical (default warning)
 */
declare(strict_types=1);
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$plantId  = (int) ($_GET['plant_id'] ?? 0);
$severity = $_GET['severity'] ?? 'warning';
if (!in_array($severity, ['warning', 'critical'], true)) {
    $severity = 'warning';
}

if ($plantId <= 0) {
    header('Location: index.php?msg=' . urlencode('Chybí plant_id'));
    exit;
}

$plant = Database::one('SELECT id, name FROM plants WHERE id = ?', [$plantId]);
if (!$plant) {
    header('Location: index.php?msg=' . urlencode('Elektrárna nenalezena'));
    exit;
}

$msg = $severity === 'critical'
    ? '🧪 Testovací KRITICKÝ alarm — výkon 0% (test)'
    : '🧪 Testovací alarm — nízký výkon (test notifikace)';

// Vlož alert
Database::pdo()->prepare(
    "INSERT INTO alerts (plant_id, type, severity, message, created_at)
     VALUES (?, 'manual', ?, ?, NOW())"
)->execute([$plantId, $severity, $msg]);

$alertId = (int) Database::pdo()->lastInsertId();

// Rozešli push hned (nechceme čekat na cron)
$config = require __DIR__ . '/../../config/config.php';
$vapid  = $config['vapid'] ?? null;

$stats  = ['sent' => 0, 'failed' => 0];
$errors = [];

if ($vapid) {
    $subs = Database::all('SELECT * FROM push_subscriptions');
    if (!empty($subs)) {
        $icon = $severity === 'critical' ? '🚨' : '⚠️';
        $payload = json_encode([
            'title' => $icon . ' ' . $plant['name'],
            'body'  => $msg,
            'icon'  => '/assets/icon-192.png',
            'url'   => '/?plant=' . $plantId,
            'tag'   => 'alert-' . $alertId,
        ]);

        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => $vapid['subject'],
                'publicKey'  => $vapid['public_key'],
                'privateKey' => $vapid['private_key'],
            ],
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

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $stats['sent']++;
            } else {
                $stats['failed']++;
                $errors[] = $report->getReason();
            }
        }

        // Označ jako odeslaný
        Database::pdo()->prepare(
            'UPDATE alerts SET push_sent_at = NOW() WHERE id = ?'
        )->execute([$alertId]);
    }
}

$backMsg = sprintf(
    '✓ Vytvořen test alarm "%s" — rozesláno %d/%d push notifikací',
    $severity,
    $stats['sent'],
    $stats['sent'] + $stats['failed']
);
if (!empty($errors)) {
    $backMsg .= ' (chyby: ' . implode(', ', array_unique($errors)) . ')';
}

header('Location: index.php?msg=' . urlencode($backMsg));
