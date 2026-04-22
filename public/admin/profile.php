<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Auth;
use FveMonitor\Lib\Passkey;

$user = Auth::currentUser();
$credentials = Passkey::getUserCredentials((int) $user['id']);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil — FVE Monitor Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .profile-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .profile-card h2 {
            margin-top: 0;
            font-size: 1.2rem;
        }
        .user-info {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px 1rem;
            font-size: 0.9rem;
        }
        .user-info dt {
            color: var(--text-dim);
            font-weight: 500;
        }
        .user-info dd {
            margin: 0;
            color: var(--text);
        }
        .passkey-list {
            list-style: none;
            padding: 0;
            margin: 1rem 0 0;
        }
        .passkey-list li {
            padding: 12px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        .passkey-info {
            flex: 1;
            min-width: 0;
        }
        .passkey-name {
            font-weight: 600;
            color: var(--text);
        }
        .passkey-meta {
            font-size: 0.8rem;
            color: var(--text-dim);
            margin-top: 2px;
        }
        .passkey-empty {
            text-align: center;
            padding: 2rem;
            color: var(--text-dim);
            background: var(--surface-2);
            border: 1px dashed var(--border);
            border-radius: 6px;
        }
        .passkey-add {
            display: flex;
            gap: 8px;
            margin-top: 1rem;
            align-items: stretch;
            flex-wrap: wrap;
        }
        .passkey-add input {
            flex: 1;
            min-width: 150px;
            padding: 10px 12px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text);
        }
        .passkey-add button {
            padding: 10px 20px;
            background: var(--accent);
            color: #000;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-delete {
            background: transparent;
            border: 1px solid var(--bad);
            color: var(--bad);
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .btn-delete:hover {
            background: rgba(248, 81, 73, 0.1);
        }
        .browser-support {
            padding: 10px 12px;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            display: none;
        }
        .browser-support.warning {
            display: block;
            background: rgba(245, 184, 0, 0.1);
            border: 1px solid var(--warn);
            color: var(--warn);
        }
    </style>
</head>
<body>
<header class="topbar">
    <h1>👤 Profil</h1>
    <div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
        <a href="index.php" style="color:var(--text-dim)">← Admin</a>
        · <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?>
        · <a href="logout.php" style="color:var(--text-dim)">Odhlásit</a>
    </div>
</header>

<main>
    <div class="profile-card">
        <h2>Uživatelské údaje</h2>
        <dl class="user-info">
            <dt>Uživatelské jméno:</dt>
            <dd><?= htmlspecialchars($user['username']) ?></dd>

            <dt>Email:</dt>
            <dd><?= htmlspecialchars($user['email']) ?></dd>

            <dt>Celé jméno:</dt>
            <dd><?= htmlspecialchars($user['full_name'] ?? '—') ?></dd>

            <dt>Role:</dt>
            <dd><?= htmlspecialchars($user['role']) ?></dd>
        </dl>
    </div>

    <div class="profile-card">
        <h2>🔐 Passkey / Biometrika</h2>
        <p style="color:var(--text-dim);font-size:0.9rem;margin-top:6px">
            Přihlašuj se otiskem prstu nebo Face ID bez zadávání hesla.
            Každé zařízení (telefon, notebook) potřebuje vlastní passkey.
        </p>

        <div id="browser-support" class="browser-support"></div>

        <?php if (empty($credentials)): ?>
            <div class="passkey-empty">
                Zatím nemáš žádné passkey. Přidej první níže.
            </div>
        <?php else: ?>
            <ul class="passkey-list">
                <?php foreach ($credentials as $c): ?>
                    <li>
                        <div class="passkey-info">
                            <div class="passkey-name">
                                🔑 <?= htmlspecialchars($c['device_name'] ?: 'Zařízení') ?>
                            </div>
                            <div class="passkey-meta">
                                Přidáno: <?= htmlspecialchars($c['created_at']) ?>
                                <?php if (!empty($c['last_used_at'])): ?>
                                    · Naposledy použito: <?= htmlspecialchars($c['last_used_at']) ?>
                                <?php endif; ?>
                                <?php if (!empty($c['transports'])): ?>
                                    · <?= htmlspecialchars($c['transports']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="btn-delete" onclick="deletePasskey(<?= (int) $c['id'] ?>)">Smazat</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="passkey-add">
            <input id="device-name" type="text"
                   placeholder="Název zařízení (např. 'Pixel 8', 'MacBook Pro')"
                   value="">
            <button id="add-passkey">🔐 Přidat passkey</button>
        </div>
    </div>
</main>

<script>
const API = '../api.php';

// Detekce podpory WebAuthn
if (!window.PublicKeyCredential) {
    const el = document.getElementById('browser-support');
    el.className = 'browser-support warning';
    el.textContent = '⚠ Tvůj prohlížeč nepodporuje WebAuthn. Potřebuješ Chrome, Safari, Firefox nebo Edge v aktuální verzi.';
}

// Helper: Base64URL → ArrayBuffer
function base64urlToBuffer(b64url) {
    const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
    const padded = b64 + '='.repeat((4 - b64.length % 4) % 4);
    const raw = atob(padded);
    const buf = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
    return buf.buffer;
}

// Helper: ArrayBuffer → Base64URL
function bufferToBase64url(buf) {
    const bytes = new Uint8Array(buf);
    let str = '';
    for (let i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function addPasskey() {
    const deviceName = document.getElementById('device-name').value.trim()
        || navigator.userAgent.match(/(Chrome|Safari|Firefox|Edg)\/[\d.]+/)?.[0]
        || 'Zařízení';

    const btn = document.getElementById('add-passkey');
    btn.disabled = true;
    btn.textContent = '⏳ Čekám na biometriku…';

    try {
        // 1) Získat options ze serveru
        const optsResp = await fetch(`${API}?action=passkey_register_start`);
        const opts = await optsResp.json();
        if (opts.error) throw new Error(opts.error);

        // 2) Převést base64 data na ArrayBuffer (WebAuthn API chce binární)
        const publicKey = {
            ...opts,
            challenge: base64urlToBuffer(opts.challenge),
            user: {
                ...opts.user,
                id: base64urlToBuffer(opts.user.id),
            },
        };
        if (opts.excludeCredentials) {
            publicKey.excludeCredentials = opts.excludeCredentials.map(c => ({
                ...c,
                id: base64urlToBuffer(c.id),
            }));
        }

        // 3) Zavolat prohlížeč - ten zobrazí dialog biometriky
        const credential = await navigator.credentials.create({ publicKey });

        // 4) Poslat na server k ověření + uložení
        const response = {
            id: credential.id,
            rawId: bufferToBase64url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON:    bufferToBase64url(credential.response.clientDataJSON),
                attestationObject: bufferToBase64url(credential.response.attestationObject),
            },
            clientExtensionResults: credential.getClientExtensionResults(),
        };

        const saveResp = await fetch(`${API}?action=passkey_register_finish`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                response: response,
                device_name: deviceName,
            }),
        });
        const saveData = await saveResp.json();
        if (saveData.error) throw new Error(saveData.error);

        alert('✓ Passkey úspěšně přidán!');
        location.reload();

    } catch (e) {
        console.error('Passkey registration error:', e);
        let msg = e.message || 'Neznámá chyba';
        if (e.name === 'NotAllowedError') {
            msg = 'Registrace byla zrušena (nebo vypršel čas).';
        } else if (e.name === 'InvalidStateError') {
            msg = 'Toto zařízení už je registrované.';
        }
        alert('Chyba: ' + msg);
    } finally {
        btn.disabled = false;
        btn.textContent = '🔐 Přidat passkey';
    }
}

async function deletePasskey(id) {
    if (!confirm('Opravdu smazat tento passkey? Zařízení se nebude moct přihlásit biometrikou.')) return;

    try {
        const resp = await fetch(`${API}?action=passkey_delete&id=${id}`);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        alert('✓ Passkey smazán');
        location.reload();
    } catch (e) {
        alert('Chyba: ' + e.message);
    }
}

document.getElementById('add-passkey').addEventListener('click', addPasskey);
</script>
</body>
</html>
