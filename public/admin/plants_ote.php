<?php
declare(strict_types=1);
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;
use FveMonitor\Lib\Auth;

$user = Auth::currentUser();

// POST = uložení formuláře
$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['plants'])) {
    $updated = 0;
    foreach ($_POST['plants'] as $plantId => $data) {
        $plantId = (int) $plantId;
        if ($plantId <= 0) continue;

        // Převod "checkbox ANO/NE" na 0/1
        $supported = !empty($data['supported']) ? 1 : 0;

        try {
            Database::pdo()->prepare(
                'UPDATE plants SET
                    ote_id = ?,
                    ote_vyrobna_id = ?,
                    ote_registrace_id = ?,
                    ean_code = ?,
                    ean_vyrobny = ?,
                    license_number = ?,
                    ico = ?,
                    evid_number = ?,
                    address_street = ?,
                    address_city = ?,
                    address_zip = ?,
                    okres = ?,
                    kraj = ?,
                    katastr_uzemi = ?,
                    katastr_code = ?,
                    parcel_number = ?,
                    operator_name = ?,
                    supported = ?,
                    support_type = ?,
                    commissioning_date = ?,
                    distributor = ?,
                    voltage_level = ?
                 WHERE id = ?'
            )->execute([
                trim($data['ote_id'] ?? '')         ?: null,
                trim($data['ote_vyrobna_id'] ?? '') ?: null,
                trim($data['ote_registrace_id'] ?? '') ?: null,
                trim($data['ean_code'] ?? '')       ?: null,
                trim($data['ean_vyrobny'] ?? '')    ?: null,
                trim($data['license_number'] ?? '') ?: null,
                trim($data['ico'] ?? '')            ?: null,
                ($data['evid_number'] ?? '') !== '' ? (int) $data['evid_number'] : null,
                trim($data['address_street'] ?? '') ?: null,
                trim($data['address_city'] ?? '')   ?: null,
                trim($data['address_zip'] ?? '')    ?: null,
                trim($data['okres'] ?? '')          ?: null,
                trim($data['kraj'] ?? '')           ?: null,
                trim($data['katastr_uzemi'] ?? '')  ?: null,
                trim($data['katastr_code'] ?? '')   ?: null,
                trim($data['parcel_number'] ?? '')  ?: null,
                trim($data['operator_name'] ?? '')  ?: null,
                $supported,
                trim($data['support_type'] ?? '')   ?: null,
                trim($data['commissioning_date'] ?? '') ?: null,
                trim($data['distributor'] ?? '')    ?: null,
                trim($data['voltage_level'] ?? 'NN'),
                $plantId,
            ]);
            $updated++;
        } catch (\Throwable $e) {
            $msg = 'Chyba u ID ' . $plantId . ': ' . $e->getMessage();
        }
    }
    if (!$msg) {
        $msg = '✓ Uloženo ' . $updated . ' elektráren';
    }
}

$plants = Database::all(
    'SELECT * FROM plants WHERE is_active = 1 ORDER BY name'
);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OTE / ERÚ metadata — FVE Monitor Admin</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .ote-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .ote-table th {
            background: var(--surface-2);
            padding: 10px 8px;
            text-align: left;
            color: var(--text-dim);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
        }
        .ote-table td {
            padding: 6px 8px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .ote-table tbody tr:hover {
            background: var(--surface-2);
        }
        .ote-table input[type="text"],
        .ote-table input[type="date"],
        .ote-table select {
            width: 100%;
            padding: 6px 8px;
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 3px;
            font-size: 0.85rem;
            box-sizing: border-box;
        }
        .ote-table input[type="text"]:focus,
        .ote-table input[type="date"]:focus,
        .ote-table select:focus {
            outline: none;
            border-color: var(--accent);
        }
        .plant-name {
            font-weight: 600;
            white-space: nowrap;
            color: var(--accent);
        }
        .plant-code {
            font-size: 0.7rem;
            color: var(--text-dim);
            margin-top: 2px;
        }
        .sticky-bar {
            position: sticky;
            top: 0;
            background: var(--bg);
            padding: 1rem 0;
            z-index: 10;
            border-bottom: 1px solid var(--border);
        }
        .checkbox-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 34px;
        }
        .checkbox-wrap input {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
        }
        .help-text {
            background: rgba(245, 184, 0, 0.08);
            border-left: 3px solid var(--accent);
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.88rem;
            color: var(--text);
        }
        .help-text code {
            background: var(--surface-2);
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 0.85em;
        }
        .table-scroll {
            overflow-x: auto;
            margin-top: 1rem;
            border: 1px solid var(--border);
            border-radius: 6px;
        }
    
/* Sticky první sloupec (Elektrárna) v obou tabulkách */
.ote-table {
    border-collapse: separate;
    border-spacing: 0;
}
.ote-table th:first-child,
.ote-table td:first-child {
    position: sticky;
    left: 0;
    background: var(--surface);
    z-index: 2;
    border-right: 2px solid var(--accent);
    min-width: 180px;
    max-width: 220px;
}
.ote-table thead th:first-child {
    background: var(--surface-2);
    z-index: 3;
}
.ote-table tbody tr:hover td:first-child {
    background: var(--surface-2);
}
.ote-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
    margin-bottom: 1rem;
    max-width: 100%;
}
.ote-table-wrap .ote-table {
    margin-bottom: 0;
}
</style>
</head>
<body>
<header class="topbar">
    <h1>🏛️ OTE / ERÚ metadata</h1>
    <div style="margin-left:auto;color:var(--text-dim);font-size:0.85rem">
        <a href="index.php" style="color:var(--text-dim)">← Admin</a>
        · <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?>
        · <a href="logout.php" style="color:var(--text-dim)">Odhlásit</a>
    </div>
</header>

<main>
    <?php if ($msg): ?>
        <div style="background:var(--surface);border-left:4px solid var(--accent);padding:12px 16px;margin-bottom:1rem;border-radius:4px">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <div class="help-text">
        <strong>📋 Kam tyto údaje patří:</strong><br>
        <strong>ID zdroje OTE</strong> — identifikátor výrobního zdroje v CS OTE (formát <code>043009_Z11</code>) — pro vykazování<br>
        <strong>ID výrobny ERÚ/OTE</strong> — z Licence ERÚ, totožné v CS OTE (formát <code>38518_T11</code>) — technická jednotka<br>
        <strong>EAN OPM</strong> — 18místný kód odběrného/předávacího místa od distributora — měří dodávku/odběr (<code>859182...</code>)<br>
        <strong>EAN výrobny</strong> — 18místný kód samotné výrobny — měří svorkovou výrobu (jen u podporovaných FVE)<br>
        <strong>Licence</strong> — číslo licence ERÚ (formát <code>1X11YY...</code>)<br>
        <strong>IČO</strong> — 8místné identifikační číslo provozovatele<br>
        <strong>Podpora</strong> — zaškrtni pokud FVE čerpá zelený bonus, výkupní cenu nebo aukční podporu<br>
        <strong>Datum uvedení do provozu</strong> — klíčové pro nárokování podpory (z licence)
    </div>

    <!-- Záložky -->
    <div class="tabs-bar">
        <button type="button" class="tab-btn active" data-tab="ids">🆔 Identifikátory</button>
        <button type="button" class="tab-btn" data-tab="address">📍 Adresa & katastr</button>
    </div>

    <form method="post">
        <div class="tab-pane active" data-pane="ids"><div class="table-scroll">
        <div class="ote-table-wrap"><table class="ote-table">
            <thead>
                <tr>
                    <th>Elektrárna</th>
                    <th style="min-width:140px">ID zdroje OTE<br><small style="font-weight:400;color:var(--text-dim)">043009_Z11</small></th>
                    <th style="min-width:140px">ID výrobny ERÚ/OTE<br><small style="font-weight:400;color:var(--text-dim)">38518_T11</small></th>
                    <th style="min-width:140px">ID registrace OTE<br><small style="font-weight:400;color:var(--text-dim)">2025001158</small></th>
                    <th style="min-width:180px">EAN OPM<br><small style="font-weight:400;color:var(--text-dim)">do/ze sítě</small></th>
                    <th style="min-width:180px">EAN výrobny<br><small style="font-weight:400;color:var(--text-dim)">svorková výroba</small></th>
                    <th style="min-width:110px">Licence ERÚ</th>
                    <th style="min-width:100px">IČO</th>
                    <th style="min-width:150px">Provozovatel</th>
                    <th style="width:70px;text-align:center">Podpora</th>
                    <th style="min-width:130px">Typ podpory</th>
                    <th style="min-width:130px">Uvedení do provozu</th>
                    <th style="min-width:90px">Distributor</th>
                    <th style="min-width:80px">Napětí</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plants as $p): ?>
                    <tr>
                        <td>
                            <div class="plant-name"><?= htmlspecialchars($p['name']) ?></div>
                            <div class="plant-code"><?= htmlspecialchars($p['code']) ?></div>
                            <div style="font-size:0.7rem;color:var(--text-dim);margin-top:2px">
                                <?= number_format((float)$p['peak_power_kwp'], 1, ',', ' ') ?> kWp
                            </div>
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][ote_id]"
                                   value="<?= htmlspecialchars($p['ote_id'] ?? '') ?>"
                                   placeholder="043009_Z11">
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][ote_vyrobna_id]"
                                   value="<?= htmlspecialchars($p['ote_vyrobna_id'] ?? '') ?>"
                                   placeholder="38518_T11">
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][ote_registrace_id]"
                                   value="<?= htmlspecialchars($p['ote_registrace_id'] ?? '') ?>"
                                   placeholder="2025001158" maxlength="20">
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][ean_code]"
                                   value="<?= htmlspecialchars($p['ean_code'] ?? '') ?>"
                                   placeholder="8591824..." maxlength="18">
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][ean_vyrobny]"
                                   value="<?= htmlspecialchars($p['ean_vyrobny'] ?? '') ?>"
                                   placeholder="8591824..." maxlength="18">
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][license_number]"
                                   value="<?= htmlspecialchars($p['license_number'] ?? '') ?>"
                                   placeholder="111234567">
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][ico]"
                                   value="<?= htmlspecialchars($p['ico'] ?? '') ?>"
                                   placeholder="12345678" maxlength="8">
                        </td>
                        <td>
                            <input type="text" name="plants[<?= $p['id'] ?>][operator_name]"
                                   value="<?= htmlspecialchars($p['operator_name'] ?? '') ?>"
                                   placeholder="Monkstone Solar s.r.o.">
                        </td>
                        <td class="checkbox-wrap">
                            <input type="checkbox" name="plants[<?= $p['id'] ?>][supported]"
                                   value="1" <?= $p['supported'] ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <select name="plants[<?= $p['id'] ?>][support_type]">
                                <option value="">— žádná —</option>
                                <?php foreach (['zeleny_bonus' => 'Zelený bonus',
                                                 'vykupni_cena' => 'Výkupní cena',
                                                 'aukcni'       => 'Aukční bonus'] as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= ($p['support_type'] ?? '') === $val ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="date" name="plants[<?= $p['id'] ?>][commissioning_date]"
                                   value="<?= htmlspecialchars($p['commissioning_date'] ?? '') ?>">
                        </td>
                        <td>
                            <select name="plants[<?= $p['id'] ?>][distributor]">
                                <option value="">—</option>
                                <?php foreach (['EG.D', 'CEZ', 'PRE', 'Teplárna Zlín'] as $d): ?>
                                    <option value="<?= $d ?>" <?= ($p['distributor'] ?? '') === $d ? 'selected' : '' ?>>
                                        <?= $d ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="plants[<?= $p['id'] ?>][voltage_level]">
                                <option value="NN"  <?= ($p['voltage_level'] ?? 'NN') === 'NN'  ? 'selected' : '' ?>>NN</option>
                                <option value="VN"  <?= ($p['voltage_level'] ?? '')   === 'VN'  ? 'selected' : '' ?>>VN</option>
                                <option value="VVN" <?= ($p['voltage_level'] ?? '')   === 'VVN' ? 'selected' : '' ?>>VVN</option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        </div>

        </div><!-- /tab-pane ids -->

        <!-- Panel: Adresa & katastr -->
        <div class="tab-pane" data-pane="address" style="display:none">
            <div class="table-scroll">
            <div class="ote-table-wrap"><table class="ote-table">
                <thead>
                    <tr>
                        <th>Elektrárna</th>
                        <th style="min-width:80px">Evid. č.<br><small style="font-weight:400;color:var(--text-dim)">z licence ERÚ</small></th>
                        <th style="min-width:200px">Ulice + č.p.<br><small style="font-weight:400;color:var(--text-dim)">např. Tovární 1234/56</small></th>
                        <th style="min-width:140px">Město<br><small style="font-weight:400;color:var(--text-dim)">Plzeň</small></th>
                        <th style="min-width:80px">PSČ<br><small style="font-weight:400;color:var(--text-dim)">301 00</small></th>
                        <th style="min-width:140px">Okres<br><small style="font-weight:400;color:var(--text-dim)">Plzeň-město</small></th>
                        <th style="min-width:140px">Kraj<br><small style="font-weight:400;color:var(--text-dim)">Plzeňský</small></th>
                        <th style="min-width:160px">Katastr. území<br><small style="font-weight:400;color:var(--text-dim)">Doubravka</small></th>
                        <th style="min-width:100px">Kód katastru<br><small style="font-weight:400;color:var(--text-dim)">722634</small></th>
                        <th style="min-width:160px">Parcela(y)<br><small style="font-weight:400;color:var(--text-dim)">12/3, 12/4</small></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plants as $p): ?>
                        <tr>
                            <td>
                                <div class="plant-name"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="plant-code"><?= htmlspecialchars($p['code']) ?></div>
                            </td>
                            <td>
                                <input type="number" name="plants[<?= $p['id'] ?>][evid_number]"
                                       value="<?= htmlspecialchars((string) ($p['evid_number'] ?? '')) ?>"
                                       placeholder="1" min="1">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][address_street]"
                                       value="<?= htmlspecialchars($p['address_street'] ?? '') ?>"
                                       placeholder="Gerská 2030/23">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][address_city]"
                                       value="<?= htmlspecialchars($p['address_city'] ?? '') ?>"
                                       placeholder="Plzeň">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][address_zip]"
                                       value="<?= htmlspecialchars($p['address_zip'] ?? '') ?>"
                                       placeholder="32300" maxlength="6">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][okres]"
                                       value="<?= htmlspecialchars($p['okres'] ?? '') ?>"
                                       placeholder="Plzeň-město">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][kraj]"
                                       value="<?= htmlspecialchars($p['kraj'] ?? '') ?>"
                                       placeholder="Plzeňský">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][katastr_uzemi]"
                                       value="<?= htmlspecialchars($p['katastr_uzemi'] ?? '') ?>"
                                       placeholder="Bolevec">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][katastr_code]"
                                       value="<?= htmlspecialchars($p['katastr_code'] ?? '') ?>"
                                       placeholder="722120">
                            </td>
                            <td>
                                <input type="text" name="plants[<?= $p['id'] ?>][parcel_number]"
                                       value="<?= htmlspecialchars($p['parcel_number'] ?? '') ?>"
                                       placeholder="1626/219">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            </div>
        </div><!-- /tab-pane address -->

        <div style="margin-top:1rem;display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-primary" style="background:var(--accent);color:#000;border:none;padding:10px 24px;border-radius:4px;font-weight:600;cursor:pointer">
                💾 Uložit všechny změny
            </button>
            <span style="color:var(--text-dim);font-size:0.85rem">
                Tip: Tab přejde mezi poli, Enter uloží formulář
            </span>
        </div>
    </form>

<style>
.tabs-bar {
    display: flex;
    gap: 4px;
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--border);
}
.tab-btn {
    background: transparent;
    color: var(--text-dim);
    border: 1px solid var(--border);
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    padding: 8px 16px;
    cursor: pointer;
    font-size: 0.9rem;
    margin-bottom: -1px;
    transition: background 0.15s, color 0.15s;
}
.tab-btn.active {
    background: var(--surface);
    color: var(--accent);
    border-color: var(--accent);
}
.tab-btn:hover:not(.active) {
    background: var(--surface-2);
    color: var(--text);
}
</style>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.querySelectorAll('.tab-pane').forEach(p => {
            p.style.display = p.dataset.pane === tab ? '' : 'none';
            p.classList.toggle('active', p.dataset.pane === tab);
        });
    });
});
</script>

</main>
</body>
</html>
