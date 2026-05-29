<?php
/**
 * Admin: správa uživatelů + permissions matrix
 */
declare(strict_types=1);
require __DIR__ . '/_auth.php';
\FveMonitor\Lib\Acl::requireAccess('admin_users');

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Acl;
use FveMonitor\Lib\Auth;

$msg = $err = null;

// ─── POST akce: toggle active / delete ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);
    $me     = (int)Auth::currentUser()['id'];

    if ($uid <= 0) {
        $err = 'Neplatné user_id';
    } elseif ($action === 'toggle_active') {
        if ($uid === $me) {
            $err = 'Nemůžete deaktivovat sami sebe.';
        } else {
            Database::pdo()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$uid]);
            $msg = 'Stav účtu změněn.';
        }
    } elseif ($action === 'delete') {
        if ($uid === $me) {
            $err = 'Nemůžete smazat sami sebe.';
        } else {
            Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $msg = 'Uživatel smazán.';
        }
    } elseif ($action === 'toggle_page_public') {
        $pageKey = $_POST['page_key'] ?? '';
        if ($pageKey) {
            Database::pdo()
                ->prepare('UPDATE acl_pages SET is_public = 1 - is_public WHERE page_key = ?')
                ->execute([$pageKey]);
            $msg = "Stránka '$pageKey': přepnuta viditelnost pro hosty.";
        }
    }
}

// ─── Načti uživatele s počtem oprávnění ───
$users = Database::all(
    "SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.last_login_at, u.created_at,
            COUNT(p.page_key) AS perm_count
     FROM users u
     LEFT JOIN user_page_permissions p ON p.user_id = u.id
     GROUP BY u.id
     ORDER BY u.is_active DESC, u.username"
);

$pages = Acl::pages();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Správa uživatelů — FVE Monitor</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="admin.css">
<style>
.user-table th, .user-table td { padding: 8px 10px; }
.user-table .role-admin    { color: var(--accent); font-weight: 600; }
.user-table .role-operator { color: var(--text); }
.user-table .role-viewer   { color: var(--text-dim); }
.user-table .inactive    { opacity: 0.45; }
.user-table .actions     { display:flex; gap:6px; flex-wrap:wrap; }
.user-table .btn-sm      { padding: 3px 9px; font-size: 0.82rem; }
.perm-count              { display:inline-block; padding: 2px 8px; background: var(--surface-2); border-radius:10px; font-size: 0.82rem; }

.pages-section {
    margin-top: 2.5rem; padding: 16px; background: var(--surface);
    border: 1px solid var(--border); border-radius: 6px;
}
.pages-section h3 { margin: 0 0 12px; font-size: 1rem; }
.pages-grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); gap:8px; }
.page-row {
    display:flex; align-items:center; gap:10px;
    padding: 8px 12px; background: var(--surface-2);
    border-radius:4px; font-size:0.9rem;
}
.page-row .ic { font-size: 1.2em; }
.page-row .ttl { flex: 1; }
.page-row .public-badge {
    padding: 2px 8px; background: rgba(0,200,0,0.18); color: var(--good);
    border-radius:10px; font-size: 0.72rem; font-weight: 600;
}
.page-row form { margin: 0; }
.page-row button.toggle {
    padding: 2px 10px; font-size: 0.75rem;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--text-dim); border-radius: 4px; cursor: pointer;
}
.page-row button.toggle:hover { color: var(--text); border-color: var(--accent); }
</style>
</head>
<body>
<?php
$pageHeading = '👥 Správa uživatelů';
$activePage  = 'admin_users';
require __DIR__ . '/../_topbar.php';
?>

<main>
    <?php if ($msg): ?>
        <div style="background:var(--surface);border-left:4px solid var(--good);padding:10px 14px;margin-bottom:1rem;border-radius:4px">
            <?= h($msg) ?>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div style="background:var(--surface);border-left:4px solid var(--bad);padding:10px 14px;margin-bottom:1rem;border-radius:4px">
            ⚠ <?= h($err) ?>
        </div>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h2 style="margin:0">Uživatelé (<?= count($users) ?>)</h2>
        <a href="user_edit.php" class="btn">➕ Nový uživatel</a>
    </div>

    <table class="admin-table user-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Jméno</th>
                <th>Email</th>
                <th>Role</th>
                <th>Práva</th>
                <th>Poslední login</th>
                <th>Stav</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $isInactive = !(int)$u['is_active'];
            $isMe = (int)$u['id'] === (int)Auth::currentUser()['id'];
        ?>
            <tr class="<?= $isInactive ? 'inactive' : '' ?>">
                <td>
                    <strong><?= h($u['username']) ?></strong>
                    <?php if ($isMe): ?><span class="small">(vy)</span><?php endif; ?>
                </td>
                <td><?= h($u['full_name']) ?></td>
                <td class="small mono"><?= h($u['email']) ?></td>
                <td class="role-<?= h($u['role']) ?>"><?= h($u['role']) ?></td>
                <td><span class="perm-count"><?= (int)$u['perm_count'] ?> / <?= count($pages) ?></span></td>
                <td class="small mono"><?= h($u['last_login_at'] ?? '—') ?></td>
                <td>
                    <?= $isInactive ? '<span style="color:var(--bad)">⏸ zablokován</span>' : '<span style="color:var(--good)">● aktivní</span>' ?>
                </td>
                <td class="actions">
                    <a href="user_edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm">✏ Upravit</a>
                    <?php if (!$isMe): ?>
                    <form method="post" style="margin:0" onsubmit="return confirm('Změnit stav účtu?')">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost">
                            <?= $isInactive ? '▶ Odblokovat' : '⏸ Blokovat' ?>
                        </button>
                    </form>
                    <form method="post" style="margin:0" onsubmit="return confirm('Opravdu smazat uživatele <?= h($u['username']) ?>? Smazat NELZE VRÁTIT.')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">🗑</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Sekce: veřejné stránky -->
    <div class="pages-section">
        <h3>🌐 Veřejné stránky (přístupné bez přihlášení)</h3>
        <p class="small" style="margin: 0 0 12px">Stránky označené <span class="public-badge">veřejná</span> může otevřít kdokoliv, ostatní vyžadují přihlášení a oprávnění.</p>
        <div class="pages-grid">
            <?php foreach ($pages as $p): ?>
                <div class="page-row">
                    <span class="ic"><?= $p['icon'] ?: '•' ?></span>
                    <span class="ttl"><?= h($p['title']) ?></span>
                    <?php if ((int)$p['is_public']): ?>
                        <span class="public-badge">veřejná</span>
                    <?php endif; ?>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="action" value="toggle_page_public">
                        <input type="hidden" name="page_key" value="<?= h($p['page_key']) ?>">
                        <button type="submit" class="toggle">
                            <?= (int)$p['is_public'] ? 'skrýt' : 'zveřejnit' ?>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>
</body>
</html>
