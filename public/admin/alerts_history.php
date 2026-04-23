<?php
/**
 * Admin: historie alertů s auditem (kdo kdy co potvrdil + komentář).
 *
 * Filtry:
 *   ?status=all|active|acknowledged (default: all)
 *   ?plant_id=N
 *   ?severity=info|warning|critical
 *   ?days=N (výchozích 30)
 */
declare(strict_types=1);
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;

// Filtry z URL
$status   = $_GET['status']   ?? 'all';
$plantId  = (int)($_GET['plant_id'] ?? 0);
$severity = $_GET['severity'] ?? '';
$days     = (int)($_GET['days'] ?? 30);
$days     = max(1, min($days, 365));

// WHERE klauzule
$where   = ['a.created_at > NOW() - INTERVAL ? DAY'];
$params  = [$days];

if ($status === 'active')       { $where[] = 'a.acknowledged_at IS NULL'; }
elseif ($status === 'acknowledged') { $where[] = 'a.acknowledged_at IS NOT NULL'; }

if ($plantId > 0) {
    $where[]  = 'a.plant_id = ?';
    $params[] = $plantId;
}

if (in_array($severity, ['info', 'warning', 'critical'], true)) {
    $where[]  = 'a.severity = ?';
    $params[] = $severity;
}

$sql = "
    SELECT a.*,
           p.code AS plant_code, p.name AS plant_name,
           u.username AS ack_username, u.full_name AS ack_full_name
    FROM alerts a
    JOIN plants p ON p.id = a.plant_id
    LEFT JOIN users u ON u.id = a.acknowledged_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.created_at DESC
    LIMIT 500
";

$alerts = Database::all($sql, $params);
$plants = Database::all('SELECT id, code, name FROM plants ORDER BY name');

// Statistiky
$stats = Database::one(
    "SELECT
        SUM(CASE WHEN acknowledged_at IS NULL THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) AS acknowledged,
        COUNT(*) AS total
     FROM alerts WHERE created_at > NOW() - INTERVAL ? DAY",
    [$days]
);

function severityBadge(string $sev): string {
    $colors = [
        'info'     => 'background:#1a2330;color:#58a6ff',
        'warning'  => 'background:rgba(245,184,0,0.2);color:#f5b800',
        'critical' => 'background:rgba(248,81,73,0.2);color:#f85149',
    ];
    $icons = ['info' => 'ℹ️', 'warning' => '⚠️', 'critical' => '🚨'];
    $style = $colors[$sev] ?? '';
    $icon  = $icons[$sev] ?? '';
    return "<span style=\"$style;padding:2px 8px;border-radius:999px;font-size:0.8rem\">$icon " . htmlspecialchars($sev) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historie alertů — FVE Monitor Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: end;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .filters .field label {
            display: block;
            font-size: 0.75rem;
            color: var(--text-dim);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filters select, .filters input {
            padding: 6px 10px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 16px;
            font-size: 0.9rem;
        }
        .stat-card .stat-num {
            font-size: 1.4rem;
            font-weight: 600;
            display: block;
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface);
            border-radius: 8px;
            overflow: hidden;
        }
        .history-table th, .history-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .history-table thead {
            background: var(--surface-2);
        }
        .history-table th {
            color: var(--text-dim);
            font-size: 0.72rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .history-table tbody tr:hover {
            background: var(--surface-2);
        }
        .ack-info {
            font-size: 0.85rem;
            color: var(--text-dim);
        }
        .ack-note {
            background: var(--surface-2);
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 0.82rem;
            color: var(--text);
            font-style: italic;
            border-left: 2px solid var(--accent);
            margin-top: 3px;
        }
        .status-active {
            color: var(--bad);
            font-weight: 600;
        }
        .status-ack {
            color: var(--good);
        }
        @media (max-width: 800px) {
            .filters { flex-direction: column; align-items: stretch; }
            .history-table { font-size: 0.85rem; }
            .history-table .col-message { max-width: 200px; }
        }
        .btn-details {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-dim);
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 3px;
            margin-right: 6px;
            font-size: 0.9rem;
        }
        .btn-details:hover {
            background: var(--surface-2);
            border-color: var(--accent);
            color: var(--accent);
        }
        .details-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            display: flex !important;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .details-modal[style*="none"] { display: none !important; }
        .details-modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            max-width: 800px;
            width: 100%;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
        }
        .details-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .details-modal-header h3 {
            margin: 0;
            font-size: 1rem;
        }
        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-dim);
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            padding: 0 6px;
        }
        .modal-close:hover { color: var(--text); }
        .details-modal-body {
            overflow: auto;
            padding: 1rem 1.5rem;
        }
        #modal-json {
            background: var(--surface-2);
            padding: 12px;
            border-radius: 4px;
            font-size: 0.82rem;
            color: var(--text);
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>
<body>
<?php $u = \FveMonitor\Lib\Auth::currentUser(); ?>
<header class="topbar">
    <h1>📋 Historie alertů</h1>
    <div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
        <a href="index.php" style="color:var(--text-dim)">← Seznam elektráren</a>
        · <?= htmlspecialchars($u['full_name'] ?? $u['username']) ?>
        · <a href="logout.php" style="color:var(--text-dim)">Odhlásit</a>
    </div>
</header>

<main>
    <!-- Statistiky za období -->
    <div class="stats">
        <div class="stat-card">
            <span class="stat-num"><?= (int) $stats['total'] ?></span>
            Celkem alertů
        </div>
        <div class="stat-card">
            <span class="stat-num status-active"><?= (int) $stats['active'] ?></span>
            Aktivní (nepotvrzené)
        </div>
        <div class="stat-card">
            <span class="stat-num status-ack"><?= (int) $stats['acknowledged'] ?></span>
            Potvrzené
        </div>
        <div class="stat-card">
            <span class="stat-num"><?= $days ?></span>
            Dní nazpět
        </div>
    </div>

    <!-- Filtry -->
    <form method="get" class="filters">
        <div class="field">
            <label>Stav</label>
            <select name="status">
                <option value="all"          <?= $status === 'all'          ? 'selected' : '' ?>>Vše</option>
                <option value="active"       <?= $status === 'active'       ? 'selected' : '' ?>>Aktivní</option>
                <option value="acknowledged" <?= $status === 'acknowledged' ? 'selected' : '' ?>>Potvrzené</option>
            </select>
        </div>
        <div class="field">
            <label>Elektrárna</label>
            <select name="plant_id">
                <option value="0">Všechny</option>
                <?php foreach ($plants as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $plantId === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Závažnost</label>
            <select name="severity">
                <option value="">Vše</option>
                <option value="info"     <?= $severity === 'info'     ? 'selected' : '' ?>>ℹ️ Info</option>
                <option value="warning"  <?= $severity === 'warning'  ? 'selected' : '' ?>>⚠️ Warning</option>
                <option value="critical" <?= $severity === 'critical' ? 'selected' : '' ?>>🚨 Critical</option>
            </select>
        </div>
        <div class="field">
            <label>Období (dny)</label>
            <input type="number" name="days" min="1" max="365" value="<?= $days ?>" style="width:80px">
        </div>
        <button type="submit" class="btn btn-primary">Filtrovat</button>
        <a href="alerts_history.php" class="btn btn-ghost">Vymazat</a>
    </form>

    <!-- Tabulka alertů -->
    <div style="overflow-x:auto">
    <table class="history-table">
        <thead>
            <tr>
                <th>Vzniklo</th>
                <th>Elektrárna</th>
                <th>Typ</th>
                <th>Závažnost</th>
                <th class="col-message">Zpráva</th>
                <th>Stav / Audit</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($alerts)): ?>
                <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-dim)">
                    Žádné alerty podle aktuálních filtrů.
                </td></tr>
            <?php else: foreach ($alerts as $a): ?>
                <tr>
                    <td style="white-space:nowrap">
                        <?= htmlspecialchars($a['created_at']) ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($a['plant_name']) ?></strong><br>
                        <small style="color:var(--text-dim)"><?= htmlspecialchars($a['plant_code']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($a['type']) ?></td>
                    <td><?= severityBadge($a['severity']) ?></td>
                    <td class="col-message"><?= htmlspecialchars($a['message']) ?></td>
                    <td>
                        <?php if (!empty($a['metric'])): ?>
                            <button class="btn-details" onclick="showDetails(<?= (int) $a['id'] ?>, this)" type="button" title="Zobrazit raw data">📊</button>
                        <?php endif; ?>
                        <?php if ($a['acknowledged_at'] === null): ?>
                            <span class="status-active">● Aktivní</span>
                        <?php else: ?>
                            <span class="status-ack">✓ Potvrzeno</span>
                            <div class="ack-info">
                                <?= htmlspecialchars($a['acknowledged_at']) ?>
                                <?php if ($a['ack_username']): ?>
                                    · <strong><?= htmlspecialchars($a['ack_full_name'] ?? $a['ack_username']) ?></strong>
                                <?php else: ?>
                                    · <em style="color:var(--text-dim)">(před auditem)</em>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($a['acknowledgement_note'])): ?>
                                <div class="ack-note">💬 <?= htmlspecialchars($a['acknowledgement_note']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <p style="text-align:center;margin-top:1rem;color:var(--text-dim);font-size:0.85rem">
        Zobrazeno <?= count($alerts) ?> záznamů (max 500)
    </p>

    <!-- Skryté JSON detaily pro modal -->
    <?php foreach ($alerts as $a): ?>
        <?php if (!empty($a['metric'])): ?>
            <pre id="metric-<?= (int) $a['id'] ?>" style="display:none"><?= htmlspecialchars(json_encode(json_decode($a['metric'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Modal -->
    <div id="details-modal" class="details-modal" onclick="closeDetails(event)" style="display:none">
        <div class="details-modal-content" onclick="event.stopPropagation()">
            <div class="details-modal-header">
                <h3>Raw data alertu #<span id="modal-alert-id">—</span></h3>
                <button class="modal-close" onclick="closeDetails()" type="button">×</button>
            </div>
            <div class="details-modal-body">
                <pre id="modal-json"></pre>
            </div>
        </div>
    </div>

</main>
<script>
function showDetails(alertId) {
    const pre = document.getElementById('metric-' + alertId);
    if (!pre) {
        alert('Žádná raw data pro tento alert');
        return;
    }
    document.getElementById('modal-alert-id').textContent = alertId;
    document.getElementById('modal-json').textContent = pre.textContent;
    document.getElementById('details-modal').style.display = 'flex';
}
function closeDetails(event) {
    if (event && event.target.id !== 'details-modal' && event.target.className !== 'modal-close') return;
    document.getElementById('details-modal').style.display = 'none';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDetails();
});
</script>
</body>
</html>
