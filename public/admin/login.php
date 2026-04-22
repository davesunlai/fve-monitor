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
        .passkey-section {
            margin-top: 1.5rem;
        }
        .passkey-divider {
            text-align: center;
            color: var(--text-dim);
            font-size: 0.8rem;
            margin: 1rem 0;
            position: relative;
        }
        .passkey-divider::before,
        .passkey-divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: var(--border);
        }
        .passkey-divider::before { left: 0; }
        .passkey-divider::after { right: 0; }
        .passkey-divider span {
            background: var(--surface);
            padding: 0 10px;
        }
        .passkey-login-btn {
            width: 100%;
            padding: 12px;
            background: transparent;
            color: var(--accent);
            border: 1px solid var(--accent);
            border-radius: 4px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
        }
        .passkey-login-btn:hover {
            background: rgba(245, 184, 0, 0.1);
        }
        .passkey-login-btn:disabled {
            opacity: 0.5;
            cursor: wait;
        }
        .passkey-status {
            margin-top: 10px;
            font-size: 0.85rem;
            text-align: center;
        }
        .passkey-status.error { color: var(--bad); }
        .passkey-status.success { color: var(--good); }
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

        <div class="passkey-section">
            <div class="passkey-divider"><span>nebo</span></div>
            <button type="button" id="passkey-login" class="passkey-login-btn">
                🔐 Přihlásit biometrikou (Passkey)
            </button>
            <div id="passkey-status" class="passkey-status"></div>
        </div>

        <p style="text-align:center;margin-top:1.5rem;font-size:0.85rem;color:var(--text-dim)">
            <a href="/" style="color:var(--text-dim)">← Zpět na dashboard</a>
        </p>
    </div>
</div>
<script>
function base64urlToBuffer(b64url) {
    const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
    const padded = b64 + '='.repeat((4 - b64.length % 4) % 4);
    const raw = atob(padded);
    const buf = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
    return buf.buffer;
}

function bufferToBase64url(buf) {
    const bytes = new Uint8Array(buf);
    let str = '';
    for (let i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

const passkeyBtn = document.getElementById('passkey-login');
const statusEl = document.getElementById('passkey-status');

if (!window.PublicKeyCredential) {
    if (passkeyBtn) passkeyBtn.style.display = 'none';
    if (statusEl) statusEl.textContent = 'Passkey není v tomto prohlížeči podporován.';
}

passkeyBtn?.addEventListener('click', async () => {
    passkeyBtn.disabled = true;
    statusEl.className = 'passkey-status';
    statusEl.textContent = '⏳ Čekám na biometriku...';

    try {
        const optsResp = await fetch('../api.php?action=passkey_login_start');
        const opts = await optsResp.json();
        if (opts.error) throw new Error(opts.error);

        const publicKey = {
            ...opts,
            challenge: base64urlToBuffer(opts.challenge),
        };
        if (opts.allowCredentials) {
            publicKey.allowCredentials = opts.allowCredentials.map(c => ({
                ...c,
                id: base64urlToBuffer(c.id),
            }));
        }

        const credential = await navigator.credentials.get({ publicKey });

        const response = {
            id: credential.id,
            rawId: bufferToBase64url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON:    bufferToBase64url(credential.response.clientDataJSON),
                authenticatorData: bufferToBase64url(credential.response.authenticatorData),
                signature:         bufferToBase64url(credential.response.signature),
                userHandle: credential.response.userHandle
                    ? bufferToBase64url(credential.response.userHandle) : null,
            },
            clientExtensionResults: credential.getClientExtensionResults(),
        };

        const loginResp = await fetch('../api.php?action=passkey_login_finish', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(response),
        });
        const loginData = await loginResp.json();
        if (loginData.error) throw new Error(loginData.error);

        statusEl.className = 'passkey-status success';
        statusEl.textContent = '✓ Přihlášeno. Přesměrovávám...';

        const params = new URLSearchParams(location.search);
        const redirect = params.get('r') || 'index.php';
        setTimeout(() => location.href = redirect, 500);

    } catch (e) {
        console.error('Passkey login error:', e);
        let msg = e.message || 'Neznámá chyba';
        if (e.name === 'NotAllowedError') msg = 'Přihlášení bylo zrušeno nebo vypršel čas.';

        statusEl.className = 'passkey-status error';
        statusEl.textContent = '⚠ ' + msg;
    } finally {
        passkeyBtn.disabled = false;
    }
});
</script>
</body>
</html>
