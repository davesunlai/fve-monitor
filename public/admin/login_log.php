<?php
/**
 * Admin — Login & Activity log
 * 3 záložky: login historie, aktivně online, activity log
 * + cleanup starých záznamů
 */
declare(strict_types=1);
require __DIR__ . '/_auth.php';
\FveMonitor\Lib\Acl::requireAccess('admin_login_log');

use FveMonitor\Lib\Database;

$tab = $_GET['tab'] ?? 'login';
$msg = null;

// ────────────────────────────────────────────
// Cleanup action
// ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cleanup') {
    $before = trim($_POST['cleanup_before'] ?? '');
    $tables = $_POST['cleanup_tables'] ?? [];
    if ($before && preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) && !empty($tables)) {
        $allowed = ['admin_login_log', 'activity_log', 'activity_sessions'];
        $deleted = [];
        foreach ($tables as $t) {
            if (!in_array($t, $allowed, true)) continue;
            $col = ($t === 'activity_sessions') ? 'last_seen' : 'created_at';
            $stmt = Database::pdo()->prepare("DELETE FROM `$t` WHERE `$col` < ?");
            $stmt->execute([$before . ' 00:00:00']);
            $deleted[$t] = $stmt->rowCount();
        }
        $parts = [];
        foreach ($deleted as $t => $n) $parts[] = "$t: $n";
        $msg = 'Smazáno před ' . $before . ' → ' . implode(', ', $parts);
    } else {
        $msg = '⚠ Vyplňte datum a alespoň jednu tabulku.';
    }
}

// ────────────────────────────────────────────
// Filtry (společné)
// ────────────────────────────────────────────
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo   = $_GET['date_to']   ?? '';
$fUser     = $_GET['user']      ?? '';   // username substring / 'anon'
$fIp       = $_GET['ip']        ?? '';
$fEvent    = $_GET['event']     ?? '';   // jen pro login tab
$fUri      = $_GET['uri']       ?? '';   // jen pro activity tab
$fMethod   = $_GET['method']    ?? '';   // jen pro login tab

function buildWhere(array $conds): array
{
    if (empty($conds)) return ['', []];
    $sql = ' WHERE ' . implode(' AND ', array_column($conds, 0));
    $params = [];
    foreach ($conds as $c) $params = array_merge($params, $c[1]);
    return [$sql, $params];
}

// Stránkování
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// ────────────────────────────────────────────
// Načti data podle aktivní záložky
// ────────────────────────────────────────────
$loginRows = $loginTotal = 0;
$onlineRows = [];
$actRows = $actTotal = 0;

if ($tab === 'login') {
    $conds = [];
    if ($fDateFrom) $conds[] = ['l.created_at >= ?', [$fDateFrom . ' 00:00:00']];
    if ($fDateTo)   $conds[] = ['l.created_at <= ?', [$fDateTo   . ' 23:59:59']];
    if ($fUser !== '') $conds[] = ['l.username LIKE ?', ['%' . $fUser . '%']];
    if ($fIp !== '')   $conds[] = ['l.ip LIKE ?',       ['%' . $fIp   . '%']];
    if ($fEvent !== '')  $conds[] = ['l.event = ?',   [$fEvent]];
    if ($fMethod !== '') $conds[] = ['l.method = ?',  [$fMethod]];

    [$where, $params] = buildWhere($conds);

    $loginTotal = (int) Database::one(
        "SELECT COUNT(*) AS c FROM admin_login_log l $where",
        $params
    )['c'];

    $loginRows = Database::all(
        "SELECT l.*, u.full_name
           FROM admin_login_log l
           LEFT JOIN users u ON u.id = l.user_id
           $where
           ORDER BY l.id DESC
           LIMIT $perPage OFFSET $offset",
        $params
    );

} elseif ($tab === 'online') {
    // Aktivně online = last_seen v posledních 5 minutách
    $onlineRows = Database::all(
        "SELECT s.*, u.username, u.full_name,
                TIMESTAMPDIFF(SECOND, s.last_seen, NOW()) AS seconds_ago,
                TIMESTAMPDIFF(SECOND, s.first_seen, NOW()) AS active_for_sec
           FROM activity_sessions s
           LEFT JOIN users u ON u.id = s.user_id
          WHERE s.last_seen >= NOW() - INTERVAL 5 MINUTE
          ORDER BY s.last_seen DESC"
    );

} elseif ($tab === 'activity') {
    $conds = [];
    if ($fDateFrom) $conds[] = ['a.created_at >= ?', [$fDateFrom . ' 00:00:00']];
    if ($fDateTo)   $conds[] = ['a.created_at <= ?', [$fDateTo   . ' 23:59:59']];
    if ($fIp !== '')  $conds[] = ['a.ip LIKE ?',          ['%' . $fIp  . '%']];
    if ($fUri !== '') $conds[] = ['a.request_uri LIKE ?', ['%' . $fUri . '%']];
    if ($fUser === 'anon') {
        $conds[] = ['a.user_id IS NULL', []];
    } elseif ($fUser !== '') {
        $conds[] = ['(u.username LIKE ? OR u.full_name LIKE ?)', ['%' . $fUser . '%', '%' . $fUser . '%']];
    }

    [$where, $params] = buildWhere($conds);

    $actTotal = (int) Database::one(
        "SELECT COUNT(*) AS c
           FROM activity_log a
           LEFT JOIN users u ON u.id = a.user_id
           $where",
        $params
    )['c'];

    $actRows = Database::all(
        "SELECT a.*, u.username, u.full_name
           FROM activity_log a
           LEFT JOIN users u ON u.id = a.user_id
           $where
           ORDER BY a.id DESC
           LIMIT $perPage OFFSET $offset",
        $params
    );
}

$uniqRows = [];
$uniqTotal = 0;
if ($tab === 'unique_ip') {
    // Stejné filtry jako activity log (datum, user, ip, uri)
    $conds = [];
    if ($fDateFrom) $conds[] = ['a.created_at >= ?', [$fDateFrom . ' 00:00:00']];
    if ($fDateTo)   $conds[] = ['a.created_at <= ?', [$fDateTo   . ' 23:59:59']];
    if ($fIp !== '')  $conds[] = ['a.ip LIKE ?',          ['%' . $fIp  . '%']];
    if ($fUri !== '') $conds[] = ['a.request_uri LIKE ?', ['%' . $fUri . '%']];
    if ($fUser === 'anon') {
        $conds[] = ['a.user_id IS NULL', []];
    } elseif ($fUser !== '') {
        $conds[] = ['(u.username LIKE ? OR u.full_name LIKE ?)', ['%' . $fUser . '%', '%' . $fUser . '%']];
    }
    [$where, $params] = buildWhere($conds);

    $uniqTotal = (int) Database::one(
        "SELECT COUNT(DISTINCT a.ip) AS c
           FROM activity_log a
           LEFT JOIN users u ON u.id = a.user_id
           $where",
        $params
    )['c'];

    // Agregace per IP — řazeno podle nejnovější aktivity (last_seen DESC).
    // SUBSTRING_INDEX + GROUP_CONCAT s ORDER BY id DESC = vrátí hodnotu z nejnovějšího řádku.
    $uniqRows = Database::all(
        "SELECT
            a.ip,
            MIN(a.created_at) AS first_seen,
            MAX(a.created_at) AS last_seen,
            COUNT(*)          AS request_count,
            SUBSTRING_INDEX(GROUP_CONCAT(a.request_uri ORDER BY a.id DESC SEPARATOR '|||'), '|||', 1) AS last_uri,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(a.user_agent,'') ORDER BY a.id DESC SEPARATOR '|||'), '|||', 1) AS last_ua,
            SUM(a.user_id IS NOT NULL) AS auth_hits,
            SUM(a.user_id IS NULL)     AS anon_hits
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         $where
         GROUP BY a.ip
         ORDER BY last_seen DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
function fmtDuration(int $sec): string {
    if ($sec < 60)   return $sec . ' s';
    if ($sec < 3600) return floor($sec/60) . ' min';
    if ($sec < 86400) {
        $h = floor($sec/3600); $m = floor(($sec%3600)/60);
        return "{$h} h {$m} min";
    }
    $d = floor($sec/86400); $h = floor(($sec%86400)/3600);
    return "{$d} d {$h} h";
}
function shortUA(?string $ua): string {
    if (!$ua) return '—';
    // Vytáhni jen prohlížeč + OS
    $browser = 'Other';
    if (preg_match('/Edg\/([\d.]+)/', $ua, $m))           $browser = 'Edge ' . $m[1];
    elseif (preg_match('/Chrome\/([\d.]+)/', $ua, $m))    $browser = 'Chrome ' . $m[1];
    elseif (preg_match('/Firefox\/([\d.]+)/', $ua, $m))   $browser = 'Firefox ' . $m[1];
    elseif (preg_match('/Safari\/([\d.]+)/', $ua, $m))    $browser = 'Safari';
    $os = '';
    if (str_contains($ua, 'Windows'))     $os = 'Win';
    elseif (str_contains($ua, 'Mac'))     $os = 'Mac';
    elseif (str_contains($ua, 'Android')) $os = 'Android';
    elseif (str_contains($ua, 'iPhone'))  $os = 'iOS';
    elseif (str_contains($ua, 'Linux'))   $os = 'Linux';
    return $browser . ($os ? " · $os" : '');
}

// Querystring pro stránkování (zachová filtry)
$qsBase = $_GET; unset($qsBase['page']);
function pageLink(int $p, array $qsBase): string {
    $qsBase['page'] = $p;
    return '?' . http_build_query($qsBase);
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activity log — FVE Monitor</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="admin.css">
<style>
    .tabs { display:flex; gap:0; margin: 1rem 0; border-bottom: 1px solid var(--border); }
    .tabs a {
        padding: 10px 18px; color: var(--text-dim); text-decoration: none;
        border-bottom: 2px solid transparent; font-size: 0.95rem;
    }
    .tabs a.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 600; }
    .tabs a:hover { color: var(--text); }

    .filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1rem;
               padding:12px; background: var(--surface); border-radius:6px; }
    .filters input, .filters select {
        padding:6px 10px; background:var(--surface-2); border:1px solid var(--border);
        border-radius:4px; color:var(--text); font-size:0.9rem;
    }
    .filters label { display:flex; flex-direction:column; gap:4px; font-size:0.8rem; color:var(--text-dim); }
    .filters .actions { display:flex; gap:6px; align-items:flex-end; }

    .ev-login_success { color: var(--good); }
    .ev-login_fail    { color: var(--bad);  }
    .ev-logout        { color: var(--text-dim); }

    .mono { font-family: monospace; font-size: 0.85em; }
    .small { font-size: 0.85em; color: var(--text-dim); }
    .pulse { color: var(--good); }
    .pulse::before { content: '● '; animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

    .pager { margin-top: 1rem; display:flex; gap:6px; align-items:center; }
    .pager a { padding: 4px 10px; border:1px solid var(--border); border-radius:4px;
               color:var(--text); text-decoration:none; }
    .pager a:hover { background: var(--surface); }
    .pager span.current { padding: 4px 10px; background: var(--accent); color:#000; border-radius:4px; }
    .pager span.disabled { padding: 4px 10px; color: var(--text-dim); }

    .cleanup-box { margin-top: 2rem; padding: 16px; background: var(--surface);
                   border: 1px solid var(--border); border-radius:6px; }
    .cleanup-box h3 { margin: 0 0 12px; font-size: 1rem; }
    .cleanup-box form { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .cleanup-box label.cb { display:inline-flex; align-items:center; gap:4px; font-size:0.9rem; }
</style>
</head>
<body>
<?php
$pageHeading = '📒 Activity log';
$activePage  = 'admin_login_log';
require __DIR__ . '/../_topbar.php';
?>

<main>
    <?php if ($msg): ?>
        <div style="background:var(--surface);border-left:4px solid var(--accent);padding:10px 14px;margin-bottom:1rem;border-radius:4px">
            <?= h($msg) ?>
        </div>
    <?php endif; ?>

    <nav class="tabs">
        <a href="?tab=login"    class="<?= $tab==='login'?'active':'' ?>">🔐 Login historie</a>
        <a href="?tab=online"   class="<?= $tab==='online'?'active':'' ?>">🟢 Aktivně online</a>
        <a href="?tab=activity" class="<?= $tab==='activity'?'active':'' ?>">📊 Activity log</a>
        <a href="?tab=unique_ip" class="<?= $tab==='unique_ip'?'active':'' ?>">🌐 Unikátní IP</a>
    </nav>

<?php if ($tab === 'login'): ?>
    <form method="get" class="filters">
        <input type="hidden" name="tab" value="login">
        <label>Od <input type="date" name="date_from" value="<?= h($fDateFrom) ?>"></label>
        <label>Do <input type="date" name="date_to" value="<?= h($fDateTo) ?>"></label>
        <label>Uživatel <input type="text" name="user" value="<?= h($fUser) ?>" placeholder="username"></label>
        <label>IP <input type="text" name="ip" value="<?= h($fIp) ?>" placeholder="217.66..."></label>
        <label>Událost
            <select name="event">
                <option value="">— vše —</option>
                <option value="login_success" <?= $fEvent==='login_success'?'selected':'' ?>>login_success</option>
                <option value="login_fail"    <?= $fEvent==='login_fail'?'selected':''    ?>>login_fail</option>
                <option value="logout"        <?= $fEvent==='logout'?'selected':''        ?>>logout</option>
            </select>
        </label>
        <label>Metoda
            <select name="method">
                <option value="">— vše —</option>
                <option value="password" <?= $fMethod==='password'?'selected':'' ?>>password</option>
                <option value="passkey"  <?= $fMethod==='passkey'?'selected':''  ?>>passkey</option>
            </select>
        </label>
        <div class="actions">
            <button type="submit" class="btn">Filtrovat</button>
            <a href="?tab=login" class="btn btn-ghost">Reset</a>
        </div>
    </form>

    <p class="small">Celkem <?= $loginTotal ?> záznamů</p>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Kdy</th>
                <th>Uživatel</th>
                <th>Událost</th>
                <th>Metoda</th>
                <th>IP</th>
                <th>Prohlížeč</th>
                <th>Délka session</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($loginRows as $r):
            $duration = '—';
            if ($r['event'] === 'login_success') {
                if ($r['logged_out_at']) {
                    $sec = strtotime($r['logged_out_at']) - strtotime($r['created_at']);
                    $duration = fmtDuration(max(0,$sec));
                } else {
                    // Stále otevřená? Zkus heartbeat
                    $sk = $r['session_key'];
                    if ($sk) {
                        $hb = Database::one(
                            'SELECT last_seen, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS sec_ago
                               FROM activity_sessions WHERE visitor_key = ?',
                            [$sk]
                        );
                        if ($hb && (int)$hb['sec_ago'] < 300) {
                            $alive = strtotime($hb['last_seen']) - strtotime($r['created_at']);
                            $duration = '<span class="pulse">' . fmtDuration(max(0,$alive)) . '</span>';
                        } elseif ($hb) {
                            $sec = strtotime($hb['last_seen']) - strtotime($r['created_at']);
                            $duration = fmtDuration(max(0,$sec)) . ' <span class="small">(zavřeno)</span>';
                        } else {
                            $duration = '<span class="small">neaktivní</span>';
                        }
                    }
                }
            }
        ?>
            <tr>
                <td class="mono"><?= h($r['created_at']) ?></td>
                <td>
                    <?= h($r['username']) ?>
                    <?php if ($r['full_name']): ?>
                        <div class="small"><?= h($r['full_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="ev-<?= h($r['event']) ?>"><?= h($r['event']) ?></td>
                <td><?= h($r['method']) ?></td>
                <td class="mono"><?= h($r['ip']) ?></td>
                <td class="small"><?= h(shortUA($r['user_agent'])) ?></td>
                <td><?= $duration ?></td>
                <td class="small">
                    <?php if ($r['fail_reason']): ?>
                        ⚠ <?= h($r['fail_reason']) ?>
                    <?php elseif ($r['logged_out_at']): ?>
                        ✓ ukončeno <?= h($r['logged_out_at']) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($loginRows)): ?>
            <tr><td colspan="8" class="small" style="text-align:center;padding:2rem">Žádné záznamy</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php
    $pages = max(1, (int) ceil($loginTotal / $perPage));
    if ($pages > 1): ?>
    <nav class="pager">
        <?= $page > 1 ? '<a href="'.pageLink($page-1,$qsBase).'">←</a>' : '<span class="disabled">←</span>' ?>
        <span>strana <?= $page ?> / <?= $pages ?></span>
        <?= $page < $pages ? '<a href="'.pageLink($page+1,$qsBase).'">→</a>' : '<span class="disabled">→</span>' ?>
    </nav>
    <?php endif; ?>

<?php elseif ($tab === 'online'): ?>
    <p class="small">Návštěvníci s aktivitou v posledních 5 minutách (<?= count($onlineRows) ?>)</p>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Stav</th>
                <th>Uživatel</th>
                <th>IP</th>
                <th>Poslední stránka</th>
                <th>Page views</th>
                <th>Aktivní</th>
                <th>Naposled</th>
                <th>Prohlížeč</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($onlineRows as $r): ?>
            <tr>
                <td><span class="pulse">online</span></td>
                <td>
                    <?php if ($r['user_id']): ?>
                        <strong><?= h($r['username']) ?></strong>
                        <?php if ($r['full_name']): ?>
                            <div class="small"><?= h($r['full_name']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="small">anonymní</span>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= h($r['ip']) ?></td>
                <td class="mono small"><?= h($r['last_page'] ?? '—') ?></td>
                <td><?= $r['page_views'] ?></td>
                <td><?= fmtDuration((int)$r['active_for_sec']) ?></td>
                <td class="small">před <?= fmtDuration((int)$r['seconds_ago']) ?></td>
                <td class="small"><?= h(shortUA($r['user_agent'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($onlineRows)): ?>
            <tr><td colspan="8" class="small" style="text-align:center;padding:2rem">Nikdo online</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p class="small">Stránka se neaktualizuje automaticky — obnov F5 pro nejnovější stav.</p>

<?php elseif ($tab === 'activity'): ?>
    <form method="get" class="filters">
        <input type="hidden" name="tab" value="activity">
        <label>Od <input type="date" name="date_from" value="<?= h($fDateFrom) ?>"></label>
        <label>Do <input type="date" name="date_to" value="<?= h($fDateTo) ?>"></label>
        <label>Uživatel
            <input type="text" name="user" value="<?= h($fUser) ?>" placeholder="username nebo 'anon'">
        </label>
        <label>IP <input type="text" name="ip" value="<?= h($fIp) ?>"></label>
        <label>URI <input type="text" name="uri" value="<?= h($fUri) ?>" placeholder="/admin/..."></label>
        <div class="actions">
            <button type="submit" class="btn">Filtrovat</button>
            <a href="?tab=activity" class="btn btn-ghost">Reset</a>
        </div>
    </form>

    <p class="small">
        Celkem <?= $actTotal ?> záznamů
        · <a href="?tab=unique_ip<?= $fDateFrom?'&date_from='.h($fDateFrom):'' ?><?= $fDateTo?'&date_to='.h($fDateTo):'' ?><?= $fIp?'&ip='.h($fIp):'' ?><?= $fUri?'&uri='.h($fUri):'' ?><?= $fUser?'&user='.h($fUser):'' ?>" style="color:var(--accent)">přepnout na unikátní IP →</a>
    </p>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Kdy</th>
                <th>Uživatel</th>
                <th>IP</th>
                <th>Metoda</th>
                <th>URI</th>
                <th>Referer</th>
                <th>Prohlížeč</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($actRows as $r): ?>
            <tr>
                <td class="mono"><?= h($r['created_at']) ?></td>
                <td>
                    <?php if ($r['user_id']): ?>
                        <strong><?= h($r['username']) ?></strong>
                    <?php else: ?>
                        <span class="small">anon</span>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= h($r['ip']) ?></td>
                <td class="small"><?= h($r['method']) ?></td>
                <td class="mono small"><?= h($r['request_uri']) ?></td>
                <td class="mono small"><?= h($r['referer'] ? parse_url($r['referer'], PHP_URL_PATH) : '—') ?></td>
                <td class="small"><?= h(shortUA($r['user_agent'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($actRows)): ?>
            <tr><td colspan="7" class="small" style="text-align:center;padding:2rem">Žádné záznamy</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php
    $pages = max(1, (int) ceil($actTotal / $perPage));
    if ($pages > 1): ?>
    <nav class="pager">
        <?= $page > 1 ? '<a href="'.pageLink($page-1,$qsBase).'">←</a>' : '<span class="disabled">←</span>' ?>
        <span>strana <?= $page ?> / <?= $pages ?></span>
        <?= $page < $pages ? '<a href="'.pageLink($page+1,$qsBase).'">→</a>' : '<span class="disabled">→</span>' ?>
    </nav>
    <?php endif; ?>

<?php elseif ($tab === 'unique_ip'): ?>
    <form method="get" class="filters">
        <input type="hidden" name="tab" value="unique_ip">
        <label>Od <input type="date" name="date_from" value="<?= h($fDateFrom) ?>"></label>
        <label>Do <input type="date" name="date_to" value="<?= h($fDateTo) ?>"></label>
        <label>Uživatel
            <input type="text" name="user" value="<?= h($fUser) ?>" placeholder="username nebo 'anon'">
        </label>
        <label>IP <input type="text" name="ip" value="<?= h($fIp) ?>"></label>
        <label>URI <input type="text" name="uri" value="<?= h($fUri) ?>" placeholder="/admin/..."></label>
        <div class="actions">
            <button type="submit" class="btn">Filtrovat</button>
            <a href="?tab=unique_ip" class="btn btn-ghost">Reset</a>
        </div>
    </form>

    <p class="small">
        Celkem <?= $uniqTotal ?> unikátních IP · řazeno podle poslední aktivity
        · <a href="?tab=activity<?= $fDateFrom?'&date_from='.h($fDateFrom):'' ?><?= $fDateTo?'&date_to='.h($fDateTo):'' ?>" style="color:var(--accent)">přepnout na detail →</a>
    </p>

    <table class="admin-table">
        <thead>
            <tr>
                <th>IP</th>
                <th>První návštěva</th>
                <th>Poslední návštěva</th>
                <th>Requests</th>
                <th>Auth / anon</th>
                <th>Poslední URI</th>
                <th>Prohlížeč</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($uniqRows as $r):
            $secAgo = strtotime('now') - strtotime($r['last_seen']);
            $isLive = $secAgo < 300;
        ?>
            <tr>
                <td class="mono">
                    <?php if ($isLive): ?><span class="pulse" style="font-size:0.8em">●</span> <?php endif; ?>
                    <?= h($r['ip']) ?>
                </td>
                <td class="mono small"><?= h($r['first_seen']) ?></td>
                <td class="mono small">
                    <?= h($r['last_seen']) ?>
                    <div class="small">před <?= fmtDuration((int)$secAgo) ?></div>
                </td>
                <td><strong><?= (int)$r['request_count'] ?></strong></td>
                <td class="small">
                    <?php if ((int)$r['auth_hits'] > 0): ?>
                        <span style="color:var(--good)"><?= (int)$r['auth_hits'] ?> auth</span>
                    <?php endif; ?>
                    <?php if ((int)$r['anon_hits'] > 0): ?>
                        <span style="color:var(--text-dim)"><?= (int)$r['anon_hits'] ?> anon</span>
                    <?php endif; ?>
                </td>
                <td class="mono small"><?= h($r['last_uri']) ?></td>
                <td class="small"><?= h(shortUA($r['last_ua'])) ?></td>
                <td class="small">
                    <a href="?tab=activity&ip=<?= urlencode($r['ip']) ?>" style="color:var(--accent)">detail →</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($uniqRows)): ?>
            <tr><td colspan="8" class="small" style="text-align:center;padding:2rem">Žádné záznamy</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php
    $pages = max(1, (int) ceil($uniqTotal / $perPage));
    if ($pages > 1): ?>
    <nav class="pager">
        <?= $page > 1 ? '<a href="'.pageLink($page-1,$qsBase).'">←</a>' : '<span class="disabled">←</span>' ?>
        <span>strana <?= $page ?> / <?= $pages ?></span>
        <?= $page < $pages ? '<a href="'.pageLink($page+1,$qsBase).'">→</a>' : '<span class="disabled">→</span>' ?>
    </nav>
    <?php endif; ?>

<?php endif; ?>

    <!-- Cleanup -->
    <div class="cleanup-box">
        <h3>🧹 Vyčistit staré záznamy</h3>
        <form method="post" onsubmit="return confirm('Opravdu smazat všechny záznamy starší než zvolené datum? Tuto akci nelze vrátit.')">
            <input type="hidden" name="action" value="cleanup">
            <label>Smazat vše před datem:
                <input type="date" name="cleanup_before" required>
            </label>
            <label class="cb"><input type="checkbox" name="cleanup_tables[]" value="admin_login_log" checked> Login log</label>
            <label class="cb"><input type="checkbox" name="cleanup_tables[]" value="activity_log" checked> Activity log</label>
            <label class="cb"><input type="checkbox" name="cleanup_tables[]" value="activity_sessions"> Sessions (heartbeat)</label>
            <button type="submit" class="btn btn-danger">Smazat</button>
        </form>
    </div>
</main>
</body>
</html>
