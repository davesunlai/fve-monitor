<?php
/**
 * Admin: editace / vytvoření uživatele + permission matrix
 *   GET ?id=N       → editace existujícího
 *   GET (bez id)    → nový uživatel
 */
declare(strict_types=1);
require __DIR__ . '/_auth.php';
\FveMonitor\Lib\Acl::requireAccess('admin_users');

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Acl;
use FveMonitor\Lib\Auth;

$userId   = (int)($_GET['id'] ?? 0);
$isNew    = $userId === 0;
$msg = $err = null;

// ─── Načti existujícího uživatele ───
$user = null;
if (!$isNew) {
    $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        header('Location: users.php');
        exit;
    }
}

$pages = Acl::pages();

// ─── POST: uložit změny ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = $_POST['role'] ?? 'user';
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $selectedPages = array_map('strval', $_POST['pages'] ?? []);

    // Validace
    if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $err = 'Username musí obsahovat jen písmena, čísla, tečku, podtržítko nebo pomlčku.';
    } elseif (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
        $err = 'Neplatná role.';
    } elseif ($isNew && $password === '') {
        $err = 'U nového uživatele musí být heslo.';
    } elseif ($password !== '' && strlen($password) < 6) {
        $err = 'Heslo musí mít aspoň 6 znaků.';
    } else {
        // Kontrola unikátnosti usernamu
        $dup = Database::one(
            'SELECT id FROM users WHERE username = ? AND id <> ?',
            [$username, $userId]
        );
        if ($dup) {
            $err = "Username '$username' už existuje.";
        }
    }

    if (!$err) {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare(
                    'INSERT INTO users (username, full_name, email, role, is_active, password_hash, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                )->execute([$username, $fullName, $email, $role, $isActive, $hash]);
                $userId = (int)$pdo->lastInsertId();
                $msg = "Uživatel '$username' vytvořen.";
            } else {
                $sets   = ['username = ?', 'full_name = ?', 'email = ?', 'role = ?', 'is_active = ?'];
                $params = [$username, $fullName, $email, $role, $isActive];
                if ($password !== '') {
                    $sets[]   = 'password_hash = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $params[] = $userId;
                $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')
                    ->execute($params);
                $msg = 'Změny uloženy.';
            }

            // Uložit permissions
            Acl::setUserPermissions($userId, $selectedPages);

            $pdo->commit();

            // Reload user
            $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
            $isNew = false;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $err = 'Chyba: ' . $e->getMessage();
        }
    }
}

// Aktuální permissions
$currentPerms = $isNew ? [] : Acl::userPermissions($userId);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pages'])) {
    // Pokud byly post-data (např. po validation erroru), použijeme je
    $currentPerms = array_map('strval', $_POST['pages']);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

// Hodnoty pro form (re-fill po POST chybě)
$formData = $user ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'username'  => $_POST['username']  ?? '',
        'full_name' => $_POST['full_name'] ?? '',
        'email'     => $_POST['email']     ?? '',
        'role'      => $_POST['role']      ?? 'user',
        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isNew ? 'Nový uživatel' : 'Editace uživatele' ?> — FVE Monitor</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="admin.css">
<style>
.form-grid { display:grid; grid-template-columns: 180px 1fr; gap:14px 16px; max-width: 700px; margin-bottom: 2rem; align-items:center; }
.form-grid label { font-weight: 500; color: var(--text-dim); }
.form-grid input[type="text"],
.form-grid input[type="email"],
.form-grid input[type="password"],
.form-grid select {
    width: 100%; padding: 8px 12px;
    background: var(--surface-2); color: var(--text);
    border: 1px solid var(--border); border-radius: 4px;
    font-size: 0.95rem;
}
.form-grid input:focus, .form-grid select:focus { outline:2px solid var(--accent); outline-offset:-1px; }
.form-grid .hint { font-size: 0.8rem; color: var(--text-dim); }
.form-grid .checkbox-row { display:flex; align-items:center; gap:8px; }

.perm-section { margin-top: 2rem; padding: 16px; background: var(--surface); border-radius: 6px; border: 1px solid var(--border); }
.perm-section h3 { margin: 0 0 12px; font-size: 1rem; }
.perm-grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(240px,1fr)); gap:10px; }
.perm-item {
    display:flex; align-items:center; gap:10px;
    padding: 10px 12px; background: var(--surface-2);
    border: 1px solid var(--border); border-radius: 4px;
    cursor:pointer; transition: border-color 0.15s;
}
.perm-item:hover { border-color: var(--accent); }
.perm-item input[type="checkbox"] { margin: 0; width: 16px; height: 16px; cursor: pointer; }
.perm-item.public { background: rgba(0,200,0,0.08); border-color: rgba(0,200,0,0.3); }
.perm-item.public .public-badge {
    margin-left: auto; padding: 1px 7px; background: rgba(0,200,0,0.18);
    color: var(--good); border-radius:10px; font-size: 0.7rem; font-weight: 600;
}
.perm-shortcut { display: flex; gap: 8px; margin-bottom: 10px; }
.perm-shortcut a { font-size: 0.85rem; color: var(--accent); cursor: pointer; }
.perm-shortcut a:hover { text-decoration: underline; }

.btn-bar { display:flex; gap:10px; margin-top: 1.5rem; }
</style>
</head>
<body>
<?php
$pageHeading = $isNew ? '➕ Nový uživatel' : '✏ Editace: ' . ($user['username'] ?? '');
$activePage  = 'admin_users';
require __DIR__ . '/../_topbar.php';
?>

<main>
    <a href="users.php" style="color:var(--text-dim);font-size:0.9rem">← Zpět na seznam</a>

    <?php if ($msg): ?>
        <div style="background:var(--surface);border-left:4px solid var(--good);padding:10px 14px;margin:1rem 0;border-radius:4px">
            <?= h($msg) ?>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div style="background:var(--surface);border-left:4px solid var(--bad);padding:10px 14px;margin:1rem 0;border-radius:4px">
            ⚠ <?= h($err) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <h2><?= $isNew ? 'Nový uživatel' : 'Editace uživatele' ?></h2>

        <div class="form-grid">
            <label>Username *</label>
            <div>
                <input type="text" name="username" value="<?= h($formData['username'] ?? '') ?>" required pattern="[a-zA-Z0-9._-]+">
                <div class="hint">Jen písmena, čísla, tečka, podtržítko, pomlčka</div>
            </div>

            <label>Plné jméno</label>
            <input type="text" name="full_name" value="<?= h($formData['full_name'] ?? '') ?>">

            <label>Email</label>
            <input type="email" name="email" value="<?= h($formData['email'] ?? '') ?>">

            <label>Role</label>
            <select name="role">
                <option value="operator" <?= (($formData['role'] ?? 'operator') === 'operator') ? 'selected' : '' ?>>operator (běžný uživatel — vidí stránky dle oprávnění)</option>
                <option value="viewer"   <?= (($formData['role'] ?? '') === 'viewer')   ? 'selected' : '' ?>>viewer (read-only — totéž jako operator)</option>
                <option value="admin"    <?= (($formData['role'] ?? '') === 'admin')    ? 'selected' : '' ?>>admin (správce + přístup do /admin/)</option>
            </select>

            <label>Heslo <?= $isNew ? '*' : '' ?></label>
            <div>
                <input type="password" name="password" autocomplete="new-password" <?= $isNew ? 'required' : '' ?>>
                <div class="hint"><?= $isNew ? 'Min. 6 znaků' : 'Necháte-li prázdné, heslo se nezmění.' ?></div>
            </div>

            <label>Aktivní</label>
            <div class="checkbox-row">
                <input type="checkbox" name="is_active" id="is_active" value="1" <?= !empty($formData['is_active']) || $isNew ? 'checked' : '' ?>>
                <label for="is_active" style="cursor:pointer">účet je aktivní (může se přihlásit)</label>
            </div>
        </div>

        <div class="perm-section">
            <h3>🔐 Oprávnění ke stránkám</h3>
            <p class="hint" style="margin: 0 0 10px">Zaškrtnuté stránky uvidí v menu a může je otevřít. Veřejné (zelené) stránky vidí každý.</p>
            <div class="perm-shortcut">
                <a onclick="document.querySelectorAll('.perm-item input').forEach(c => c.checked = true)">✓ Vybrat vše</a>
                <a onclick="document.querySelectorAll('.perm-item input').forEach(c => c.checked = false)">✗ Zrušit vše</a>
            </div>
            <div class="perm-grid">
                <?php foreach ($pages as $p):
                    $checked  = in_array($p['page_key'], $currentPerms, true);
                    $isPublic = (int)$p['is_public'] === 1;
                ?>
                    <label class="perm-item <?= $isPublic ? 'public' : '' ?>">
                        <input type="checkbox" name="pages[]" value="<?= h($p['page_key']) ?>" <?= $checked ? 'checked' : '' ?>>
                        <span style="font-size:1.1em"><?= $p['icon'] ?: '•' ?></span>
                        <span><?= h($p['title']) ?></span>
                        <?php if ($isPublic): ?><span class="public-badge">veřejná</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="btn-bar">
            <button type="submit" class="btn">💾 Uložit</button>
            <a href="users.php" class="btn btn-ghost">Zrušit</a>
        </div>
    </form>
</main>
</body>
</html>
