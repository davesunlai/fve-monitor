<?php
/**
 * Admin — editace/vytvoření elektrárny s klikací mapou a sekcemi panelů
 */
require __DIR__ . '/_auth.php';

use FveMonitor\Lib\Database;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$notice = null;

// ───── Zpracování formuláře ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = validateInput($_POST);
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        if ($id > 0) {
            // UPDATE
            $stmt = $pdo->prepare(
                'UPDATE plants SET
                    code = ?, name = ?, provider = ?, provider_ps_id = ?,
                    latitude = ?, longitude = ?, peak_power_kwp = ?,
                    system_loss_pct = ?, install_year = ?, degradation_pct_per_year = ?,
                    is_active = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['code'], $data['name'], $data['provider'], $data['provider_ps_id'],
                $data['latitude'], $data['longitude'], $data['peak_power_kwp'],
                $data['system_loss_pct'], $data['install_year'], $data['degradation_pct_per_year'],
                $data['is_active'], $id,
            ]);
        } else {
            // INSERT
            $stmt = $pdo->prepare(
                'INSERT INTO plants
                    (code, name, provider, provider_ps_id, latitude, longitude,
                     peak_power_kwp, tilt_deg, azimuth_deg, system_loss_pct,
                     install_year, degradation_pct_per_year, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 35, 0, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['code'], $data['name'], $data['provider'], $data['provider_ps_id'],
                $data['latitude'], $data['longitude'], $data['peak_power_kwp'],
                $data['system_loss_pct'], $data['install_year'], $data['degradation_pct_per_year'],
                $data['is_active'],
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        // Smaž všechny sekce a vytvoř znovu podle formuláře
        $pdo->prepare('DELETE FROM plant_sections WHERE plant_id = ?')->execute([$id]);

        $sumShare = 0;
        $sectionStmt = $pdo->prepare(
            'INSERT INTO plant_sections (plant_id, name, tilt_deg, azimuth_deg, power_share_pct, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($data['sections'] as $idx => $s) {
            $sumShare += $s['power_share_pct'];
            $sectionStmt->execute([
                $id, $s['name'], $s['tilt_deg'], $s['azimuth_deg'], $s['power_share_pct'], $idx,
            ]);
        }
        if (abs($sumShare - 100.0) > 0.5) {
            throw new \RuntimeException("Součet podílů sekcí musí být 100 % (máš " . $sumShare . " %)");
        }

        $pdo->commit();
        $notice = 'Uloženo.';

        header('Location: ?id=' . $id . '&saved=1');
        exit;

    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

// ───── Načtení dat pro editaci ─────
$plant = null;
$sections = [];
if ($id > 0) {
    $plant = Database::one('SELECT * FROM plants WHERE id = ?', [$id]);
    if (!$plant) {
        die('Elektrárna nenalezena.');
    }
    $sections = Database::all(
        'SELECT * FROM plant_sections WHERE plant_id = ? ORDER BY sort_order, id',
        [$id]
    );
}

if (empty($sections)) {
    $sections = [[
        'id' => null, 'name' => 'Hlavní',
        'tilt_deg' => 35, 'azimuth_deg' => 0, 'power_share_pct' => 100,
    ]];
}

// Po chybě validace (POST s errors) přepiš data hodnotami z formuláře,
// aby uživatel nemusel znovu vše vyplňovat.
if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $plant = [
        'code'            => $_POST['code'] ?? '',
        'name'            => $_POST['name'] ?? '',
        'provider'        => $_POST['provider'] ?? 'mock',
        'provider_ps_id'  => $_POST['provider_ps_id'] ?? '',
        'latitude'        => $_POST['latitude'] ?? 49.5919,
        'longitude'       => $_POST['longitude'] ?? 18.1186,
        'peak_power_kwp'  => $_POST['peak_power_kwp'] ?? 9.9,
        'system_loss_pct' => $_POST['system_loss_pct'] ?? 14.0,
        'install_year'    => $_POST['install_year'] ?? null,
        'degradation_pct_per_year' => $_POST['degradation_pct_per_year'] ?? 0.5,
        'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
    ];

    // Obnov sekce z POST polí
    $postSections = [];
    $names  = $_POST['section_name']    ?? [];
    $tilts  = $_POST['section_tilt']    ?? [];
    $azims  = $_POST['section_azimuth'] ?? [];
    $shares = $_POST['section_share']   ?? [];
    foreach ($names as $i => $n) {
        if (trim($n) === '') continue;
        $postSections[] = [
            'id' => null,
            'name' => $n,
            'tilt_deg' => (int)($tilts[$i] ?? 35),
            'azimuth_deg' => (int)($azims[$i] ?? 0),
            'power_share_pct' => (float)($shares[$i] ?? 0),
        ];
    }
    if (!empty($postSections)) {
        $sections = $postSections;
    }
}

if (!empty($_GET['saved'])) $notice = 'Uloženo.';

// ───── Helper funkce ─────
function validateInput(array $post): array
{
    $code = trim($post['code'] ?? '');
    if (!preg_match('/^[A-Z0-9_]{2,32}$/', $code)) {
        throw new \RuntimeException('Kód musí obsahovat jen A-Z, 0-9, _ (2–32 znaků)');
    }
    $name = trim($post['name'] ?? '');
    if ($name === '') throw new \RuntimeException('Název nesmí být prázdný');

    $lat = (float)($post['latitude'] ?? 0);
    $lon = (float)($post['longitude'] ?? 0);
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        throw new \RuntimeException('Neplatné GPS souřadnice');
    }
    $kwp = (float)($post['peak_power_kwp'] ?? 0);
    if ($kwp <= 0 || $kwp > 100000) {
        throw new \RuntimeException('Výkon musí být mezi 0 a 100 000 kWp');
    }

    // Sekce
    $sections = [];
    $names   = $post['section_name'] ?? [];
    $tilts   = $post['section_tilt'] ?? [];
    $azims   = $post['section_azimuth'] ?? [];
    $shares  = $post['section_share'] ?? [];

    foreach ($names as $i => $n) {
        if (trim($n) === '') continue;
        $sections[] = [
            'name' => trim($n),
            'tilt_deg' => max(0, min(90, (int)($tilts[$i] ?? 35))),
            'azimuth_deg' => max(-180, min(180, (int)($azims[$i] ?? 0))),
            'power_share_pct' => max(0, min(100, (float)($shares[$i] ?? 0))),
        ];
    }
    if (empty($sections)) {
        throw new \RuntimeException('Musíš zadat aspoň jednu sekci panelů');
    }

    $install = !empty($post['install_year']) ? (int)$post['install_year'] : null;
    if ($install !== null && ($install < 2000 || $install > (int)date('Y'))) {
        throw new \RuntimeException('Rok instalace musí být mezi 2000 a ' . date('Y'));
    }

    return [
        'code' => $code,
        'name' => $name,
        'provider' => $post['provider'] ?? 'mock',
        'provider_ps_id' => trim($post['provider_ps_id'] ?? '') ?: null,
        'latitude' => $lat,
        'longitude' => $lon,
        'peak_power_kwp' => $kwp,
        'system_loss_pct' => max(0, min(50, (float)($post['system_loss_pct'] ?? 14))),
        'install_year' => $install,
        'degradation_pct_per_year' => max(0, min(5, (float)($post['degradation_pct_per_year'] ?? 0.5))),
        'is_active' => !empty($post['is_active']) ? 1 : 0,
        'sections' => $sections,
    ];
}

// Defaulty pro nové
$defaults = [
    'code' => '', 'name' => '',
    'provider' => 'mock', 'provider_ps_id' => '',
    'latitude' => 49.5919, 'longitude' => 18.1186,  // Štramberk default
    'peak_power_kwp' => 9.9,
    'system_loss_pct' => 14.0,
    'install_year' => null,
    'degradation_pct_per_year' => 0.5,
    'is_active' => 1,
];
$p = $plant ?? $defaults;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'Upravit' : 'Nová' ?> elektrárnu — Admin</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="admin.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
<header class="topbar">
    <h1>⚙️ <?= $id ? 'Upravit elektrárnu' : 'Nová elektrárna' ?></h1>
    <div class="topbar-meta">
        <a href="index.php" class="btn btn-ghost">← Zpět na seznam</a>
    </div>
</header>

<main>
    <?php if ($notice): ?>
        <div class="notice notice-success">✓ <?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
        <div class="notice notice-error">⚠ <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <form method="post" class="form-grid">

        <section class="form-card">
            <h3>Základní info</h3>
            <div class="form-row">
                <label>Kód <small>(A-Z, 0-9, _)</small>
                    <input type="text" name="code" value="<?= htmlspecialchars($p['code']) ?>"
                        pattern="[A-Z0-9_]{2,32}" required>
                </label>
                <label>Název
                    <input type="text" name="name" value="<?= htmlspecialchars($p['name']) ?>" required>
                </label>
            </div>
            <div class="form-row">
                <label>Provider
                    <select name="provider">
                        <option value="mock" <?= $p['provider']=='mock'?'selected':'' ?>>Mock (simulace)</option>
                        <option value="isolarcloud" <?= $p['provider']=='isolarcloud'?'selected':'' ?>>iSolarCloud (Sungrow)</option>
                        <option value="solaredge" <?= $p['provider']=='solaredge'?'selected':'' ?>>SolarEdge (plánované)</option>
                    </select>
                </label>
                <label>Provider PS ID <small>(ps_id v iSolarCloud)</small>
                    <input type="text" name="provider_ps_id" value="<?= htmlspecialchars($p['provider_ps_id'] ?? '') ?>">
                </label>
            </div>
            <div class="form-row">
                <label>Výkon celkem (kWp)
                    <input type="number" name="peak_power_kwp" step="0.01" min="0.1"
                        value="<?= $p['peak_power_kwp'] ?>" required>
                </label>
                <label>Ztráty systému (%)
                    <input type="number" name="system_loss_pct" step="0.5" min="0" max="50"
                        value="<?= $p['system_loss_pct'] ?>">
                </label>
                <label class="inline">
                    <input type="checkbox" name="is_active" value="1" <?= $p['is_active']?'checked':'' ?>>
                    Aktivní
                </label>
            </div>
        </section>

        <section class="form-card">
            <h3>📍 Umístění — klikni do mapy</h3>
            <div class="form-row">
                <label>Latitude
                    <input type="number" name="latitude" id="lat" step="0.000001"
                        value="<?= $p['latitude'] ?>" required>
                </label>
                <label>Longitude
                    <input type="number" name="longitude" id="lng" step="0.000001"
                        value="<?= $p['longitude'] ?>" required>
                </label>
            </div>
            <div id="map" style="height: 400px; border-radius: 8px; margin-top: 10px;"></div>
        </section>

        <section class="form-card">
            <h3>☀️ Sekce panelů
                <small>Součet podílů = 100 %. Azimut: 0°=jih, -90°=východ, 90°=západ.</small>
            </h3>
            <div id="sections">
                <?php foreach ($sections as $i => $s): ?>
                    <div class="section-row">
                        <label>Název
                            <input type="text" name="section_name[]" value="<?= htmlspecialchars($s['name']) ?>" required>
                        </label>
                        <label>Sklon (°)
                            <input type="number" name="section_tilt[]" min="0" max="90"
                                value="<?= (int)$s['tilt_deg'] ?>" required>
                        </label>
                        <label>Azimut (°)
                            <input type="number" name="section_azimuth[]" min="-180" max="180"
                                value="<?= (int)$s['azimuth_deg'] ?>" required>
                        </label>
                        <label>Podíl výkonu (%)
                            <input type="number" name="section_share[]" step="0.1" min="0" max="100"
                                class="share-input" value="<?= $s['power_share_pct'] ?>" required>
                        </label>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeSection(this)">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-ghost" onclick="addSection()">+ Přidat sekci</button>
            <div id="share-sum" class="share-sum">Součet: <span>100</span> %</div>
        </section>

        <section class="form-card">
            <h3>⚙️ Degradace (volitelné)</h3>
            <div class="form-row">
                <label>Rok instalace
                    <input type="number" name="install_year" min="2000" max="<?= date('Y') ?>"
                        value="<?= $p['install_year'] ?? '' ?>">
                </label>
                <label>Degradace (%/rok) <small>typicky 0.5 %/rok pro c-Si</small>
                    <input type="number" name="degradation_pct_per_year" step="0.1" min="0" max="5"
                        value="<?= $p['degradation_pct_per_year'] ?>">
                </label>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">💾 Uložit</button>
            <a href="index.php" class="btn btn-ghost">Zrušit</a>
            <?php if ($id): ?>
                <a href="pvgis_refresh.php?id=<?= $id ?>" class="btn btn-warning"
                   onclick="return confirm('Po uložení změn orientace je potřeba obnovit PVGIS predikce.')">
                   ⟳ Obnovit PVGIS po uložení
                </a>
            <?php endif; ?>
        </div>
    </form>
</main>

<script>
// ── Mapa (Leaflet + OpenStreetMap) ──
const map = L.map('map').setView([<?= $p['latitude'] ?>, <?= $p['longitude'] ?>], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19,
}).addTo(map);

let marker = L.marker([<?= $p['latitude'] ?>, <?= $p['longitude'] ?>], { draggable: true }).addTo(map);
marker.on('dragend', e => {
    const pos = e.target.getLatLng();
    setLatLng(pos.lat, pos.lng);
});
map.on('click', e => {
    marker.setLatLng(e.latlng);
    setLatLng(e.latlng.lat, e.latlng.lng);
});
function setLatLng(lat, lng) {
    document.getElementById('lat').value = lat.toFixed(6);
    document.getElementById('lng').value = lng.toFixed(6);
}
document.getElementById('lat').addEventListener('change', syncMarker);
document.getElementById('lng').addEventListener('change', syncMarker);
function syncMarker() {
    const lat = parseFloat(document.getElementById('lat').value);
    const lng = parseFloat(document.getElementById('lng').value);
    if (!isNaN(lat) && !isNaN(lng)) {
        marker.setLatLng([lat, lng]);
        map.panTo([lat, lng]);
    }
}

// ── Sekce panelů ──
function addSection() {
    const html = `
        <div class="section-row">
            <label>Název <input type="text" name="section_name[]" value="Sekce" required></label>
            <label>Sklon (°) <input type="number" name="section_tilt[]" min="0" max="90" value="35" required></label>
            <label>Azimut (°) <input type="number" name="section_azimuth[]" min="-180" max="180" value="0" required></label>
            <label>Podíl (%) <input type="number" name="section_share[]" step="0.1" min="0" max="100" class="share-input" value="0" required></label>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeSection(this)">×</button>
        </div>`;
    document.getElementById('sections').insertAdjacentHTML('beforeend', html);
    attachShareListeners();
    updateShareSum();
}
function removeSection(btn) {
    if (document.querySelectorAll('.section-row').length <= 1) {
        alert('Musí zůstat aspoň jedna sekce.');
        return;
    }
    btn.closest('.section-row').remove();
    updateShareSum();
}
function updateShareSum() {
    const inputs = document.querySelectorAll('.share-input');
    let sum = 0;
    inputs.forEach(i => sum += parseFloat(i.value) || 0);
    const el = document.querySelector('#share-sum span');
    el.textContent = sum.toFixed(1);
    el.parentElement.classList.toggle('warn', Math.abs(sum - 100) > 0.5);
}
function attachShareListeners() {
    document.querySelectorAll('.share-input').forEach(i => {
        i.removeEventListener('input', updateShareSum);
        i.addEventListener('input', updateShareSum);
    });
}
attachShareListeners();
updateShareSum();
</script>
</body>
</html>
