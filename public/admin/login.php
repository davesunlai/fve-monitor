<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';

use FveMonitor\Lib\Auth;

$error = null;
$redirect = $_GET['r'] ?? 'index.php';

// Pokud už přihlášen, jen redirect
if (Auth::isLoggedIn()) {
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Vyplňte prosím uživatelské jméno i heslo.';
    } else {
        $user = Auth::login($username, $password);
        if ($user !== null) {
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Neplatné přihlašovací údaje.';
        usleep(500_000);  // 500ms delay proti brute-force
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení — FVE Monitor</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .login-wrap {
            max-width: 400px;
            margin: 10vh auto;
            padding: 2rem;
        }
        .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 2rem;
        }
        .login-card h1 {
            margin: 0 0 1.5rem;
            text-align: center;
            font-size: 1.5rem;
        }
        .login-card .field {
            margin-bottom: 1rem;
        }
        .login-card label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-dim);
            font-size: 0.85rem;
        }
        .login-card input {
            width: 100%;
            padding: 10px 12px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text);
            font-size: 1rem;
            box-sizing: border-box;
        }
        .login-card input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .login-card button {
            width: 100%;
            padding: 12px;
            background: var(--accent);
            color: #000;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.5rem;
        }
        .login-card button:hover {
            opacity: 0.9;
        }
        .login-error {
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid var(--bad);
            color: var(--bad);
            padding: 10px 12px;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <h1>☀️ FVE Monitor</h1>

        <?php if ($error): ?>
            <div class="login-error">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <div class="field">
                <label for="username">Uživatelské jméno</label>
                <input type="text" id="username" name="username"
                       autocomplete="username" autofocus required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="password">Heslo</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit">Přihlásit se</button>
        </form>

        <p style="text-align:center;margin-top:1.5rem;font-size:0.85rem;color:var(--text-dim)">
            <a href="/" style="color:var(--text-dim)">← Zpět na dashboard</a>
        </p>
    </div>
</div>
</body>
</html>
