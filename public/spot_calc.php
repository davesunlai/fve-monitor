<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Auth;

Auth::start();
if (!Auth::isLoggedIn()) {
    header('Location: admin/login.php?r=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Předvyplnění z URL nebo defaulty (D57d - elektrické topení Kopřivnice)
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));
$year   = (int) ($_GET['year']  ?? $now->format('Y'));
$month  = (int) ($_GET['month'] ?? $now->format('n'));
$tdd    = (int) ($_GET['tdd']   ?? 8);  // přímotop
$tariff = $_GET['tariff'] ?? 'D57d';
$kwhVt  = (float) ($_GET['kwh_vt'] ?? 300);
$kwhNt  = (float) ($_GET['kwh_nt'] ?? 1200);
$jisticA = (int) ($_GET['jistic_a'] ?? 25);
$jisticPh = (int) ($_GET['jistic_ph'] ?? 3);
$tradeFee = (float) ($_GET['tradefee'] ?? 482.79);
$monthlyFee = (float) ($_GET['monthly_fee'] ?? 154.88);
$distribVt = (float) ($_GET['distrib_vt'] ?? 913.27);
$distribNt = (float) ($_GET['distrib_nt'] ?? 140.97);
$jisticFee = (float) ($_GET['jistic_fee'] ?? 309.76);
$pozeKwh = (float) ($_GET['poze_kwh'] ?? 598.95);
$pozeA = (float) ($_GET['poze_a'] ?? 140.97);

$pageTitle    = 'SPOT kalkulačka — FVE Monitor';
$pageHeading  = '🧮 SPOT kalkulačka';
$activePage   = 'spot_calc';
$includeChart = true;
require __DIR__ . '/_app_head.php';
?>
<body>
<?php require __DIR__ . '/_topbar.php'; ?>

<main class="container" style="max-width:1400px;margin:1rem auto;padding:0 1rem">

    <div class="calc-grid">

        <!-- LEVÝ SLOUPEC: Form s parametry -->
        <div class="card calc-form">
            <h3 style="margin-top:0">⚙ Parametry</h3>

            <form id="calc-form" onsubmit="return false">

                <fieldset>
                    <legend>Období</legend>
                    <div class="form-row">
                        <label>Rok
                            <select name="year">
                                <?php for ($y = 2024; $y <= 2026; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </label>
                        <label>Měsíc
                            <select name="month">
                                <?php
                                $mNames = ['leden','únor','březen','duben','květen','červen','červenec','srpen','září','říjen','listopad','prosinec'];
                                for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?> – <?= $mNames[$m-1] ?></option>
                                <?php endfor; ?>
                            </select>
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Tarif a TDD profil</legend>
                    <div class="form-row">
                        <label>Distribuční sazba
                            <select name="tariff" onchange="updateDefaults(this.value)">
                                <option value="D02d" <?= $tariff === 'D02d' ? 'selected' : '' ?>>D02d – jednotarif</option>
                                <option value="D25d" <?= $tariff === 'D25d' ? 'selected' : '' ?>>D25d – akumulace 8h</option>
                                <option value="D26d" <?= $tariff === 'D26d' ? 'selected' : '' ?>>D26d – akumulace pružná</option>
                                <option value="D27d" <?= $tariff === 'D27d' ? 'selected' : '' ?>>D27d – elektromobilita</option>
                                <option value="D45d" <?= $tariff === 'D45d' ? 'selected' : '' ?>>D45d – přímotop 16h</option>
                                <option value="D56d" <?= $tariff === 'D56d' ? 'selected' : '' ?>>D56d – tepelné čerpadlo</option>
                                <option value="D57d" <?= $tariff === 'D57d' ? 'selected' : '' ?>>D57d – elektrické topení 20h</option>
                                <option value="D61d" <?= $tariff === 'D61d' ? 'selected' : '' ?>>D61d – víkendová</option>
                            </select>
                        </label>
                        <label>TDD třída
                            <select name="tdd">
                                <option value="4" <?= $tdd === 4 ? 'selected' : '' ?>>TDD4 – běžná domácnost</option>
                                <option value="5" <?= $tdd === 5 ? 'selected' : '' ?>>TDD5 – malý odběr 2T</option>
                                <option value="6" <?= $tdd === 6 ? 'selected' : '' ?>>TDD6 – akumulační topení</option>
                                <option value="7" <?= $tdd === 7 ? 'selected' : '' ?>>TDD7 – smíšené topení</option>
                                <option value="8" <?= $tdd === 8 ? 'selected' : '' ?>>TDD8 – přímotop / TC</option>
                            </select>
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Spotřeba (měsíční)</legend>
                    <div class="form-row">
                        <label>Vysoký tarif
                            <input type="number" name="kwh_vt" value="<?= $kwhVt ?>" min="0" step="10"> kWh
                        </label>
                        <label>Nízký tarif
                            <input type="number" name="kwh_nt" value="<?= $kwhNt ?>" min="0" step="10"> kWh
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Jistič</legend>
                    <div class="form-row">
                        <label>Velikost
                            <input type="number" name="jistic_a" value="<?= $jisticA ?>" min="6" max="160" step="1"> A
                        </label>
                        <label>Fází
                            <select name="jistic_ph">
                                <option value="1" <?= $jisticPh === 1 ? 'selected' : '' ?>>1 (×25A)</option>
                                <option value="3" <?= $jisticPh === 3 ? 'selected' : '' ?>>3</option>
                            </select>
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Ceník obchodník (Kč/MWh, Kč/měsíc s DPH)</legend>
                    <div class="form-row">
                        <label>Poplatek za služby obchodu
                            <input type="number" name="tradefee" value="<?= $tradeFee ?>" min="0" step="0.01"> Kč/MWh
                        </label>
                        <label>Stálá platba obchodník
                            <input type="number" name="monthly_fee" value="<?= $monthlyFee ?>" min="0" step="0.01"> Kč/měsíc
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Distribuce (Kč/MWh, Kč/měsíc s DPH)</legend>
                    <div class="form-row">
                        <label>Distribuce VT
                            <input type="number" name="distrib_vt" value="<?= $distribVt ?>" min="0" step="0.01"> Kč/MWh
                        </label>
                        <label>Distribuce NT
                            <input type="number" name="distrib_nt" value="<?= $distribNt ?>" min="0" step="0.01"> Kč/MWh
                        </label>
                    </div>
                    <div class="form-row">
                        <label>Stálá platba za jistič
                            <input type="number" name="jistic_fee" value="<?= $jisticFee ?>" min="0" step="0.01"> Kč/měsíc
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>POZE (počítá se nižší)</legend>
                    <div class="form-row">
                        <label>POZE per kWh
                            <input type="number" name="poze_kwh" value="<?= $pozeKwh ?>" min="0" step="0.01"> Kč/MWh
                        </label>
                        <label>POZE per A
                            <input type="number" name="poze_a" value="<?= $pozeA ?>" min="0" step="0.01"> Kč/A/měs
                        </label>
                    </div>
                </fieldset>

                <button type="button" onclick="recalculate()" class="btn-calc">🧮 Přepočítat</button>
            </form>
        </div>

        <!-- PRAVÝ SLOUPEC: Výsledky -->
        <div class="calc-results">
            <div id="result-stats" class="stats-grid"></div>
            <div class="card" style="margin-top:1rem">
                <div id="breakdown-title" style="font-weight:600;margin-bottom:0.75rem">Rozpis faktury</div>
                <div id="breakdown"></div>
            </div>
            <div class="card" style="margin-top:1rem">
                <div style="font-weight:600;margin-bottom:0.5rem">Profil ceny vs spotřeby (denní průměry)</div>
                <div style="position:relative;height:300px">
                    <canvas id="profile-chart"></canvas>
                </div>
            </div>
        </div>

    </div>

</main>

<style>
.calc-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 1rem;
}
@media (max-width: 900px) {
    .calc-grid { grid-template-columns: 1fr; }
}

.calc-form fieldset {
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.75rem 0.9rem;
    margin-bottom: 0.9rem;
    background: var(--surface-2);
}
.calc-form legend {
    color: var(--text-dim);
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0 6px;
}
.calc-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6rem;
    margin-bottom: 0.5rem;
}
.calc-form .form-row:last-child { margin-bottom: 0; }
.calc-form .form-row label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 0.78rem;
    color: var(--text-dim);
}
.calc-form input,
.calc-form select {
    padding: 6px 8px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    font-size: 0.9rem;
    color-scheme: dark;
}
.calc-form input:focus,
.calc-form select:focus {
    outline: none;
    border-color: var(--accent);
}
.btn-calc {
    width: 100%;
    padding: 12px;
    background: var(--accent);
    color: #000;
    border: none;
    border-radius: 6px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    margin-top: 0.5rem;
    transition: opacity 0.15s;
}
.btn-calc:hover { opacity: 0.85; }
.btn-calc:active { transform: translateY(1px); }

#breakdown {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 6px 1.5rem;
    align-items: baseline;
    font-size: 0.92rem;
    font-variant-numeric: tabular-nums;
}
#breakdown .b-label { color: var(--text-dim); }
#breakdown .b-value { text-align: right; font-weight: 500; }
#breakdown .b-pct { text-align: right; color: var(--text-dim); font-size: 0.8rem; min-width: 50px; }
#breakdown .b-sep { grid-column: 1/-1; height: 1px; background: var(--border); margin: 4px 0; }
#breakdown .b-total .b-label { color: var(--text); font-weight: 700; font-size: 1.05rem; }
#breakdown .b-total .b-value { color: var(--accent); font-weight: 700; font-size: 1.1rem; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.6rem;
}
.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.8rem 1rem;
}
.stat-card .label {
    font-size: 0.72rem;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.stat-card .value {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--text);
}
.stat-card .sub {
    font-size: 0.72rem;
    color: var(--text-dim);
    margin-top: 2px;
}
.stat-card.highlight .value { color: var(--accent); }
.stat-card.good .value { color: var(--good, #4ade80); }
.stat-card.bad .value { color: var(--bad, #f87171); }
</style>

<script>
let chart = null;

const fmt = (v, dec = 2) => {
    if (v === null || v === undefined || isNaN(v)) return '—';
    return Number(v).toLocaleString('cs-CZ', { minimumFractionDigits: dec, maximumFractionDigits: dec });
};

// Defaultní hodnoty per distribuční sazba (z ceníku ČEZ 2026)
// Formát: [tdd_doporučená, distrib_vt, distrib_nt, jistic_fee_3x25A]
const TARIFF_DEFAULTS = {
    'D02d': { tdd: 4, distrib_vt: 2515.08, distrib_nt: 0,      jistic_3x25: 309.76 },
    'D25d': { tdd: 6, distrib_vt: 2725.46, distrib_nt: 140.97, jistic_3x25: 325.49 },
    'D26d': { tdd: 6, distrib_vt: 1454.49, distrib_nt: 140.97, jistic_3x25: 438.02 },
    'D27d': { tdd: 7, distrib_vt: 2725.46, distrib_nt: 140.97, jistic_3x25: 308.55 },
    'D45d': { tdd: 8, distrib_vt: 913.27,  distrib_nt: 140.97, jistic_3x25: 671.55 },
    'D56d': { tdd: 7, distrib_vt: 913.27,  distrib_nt: 140.97, jistic_3x25: 671.55 },
    'D57d': { tdd: 8, distrib_vt: 913.27,  distrib_nt: 140.97, jistic_3x25: 671.55 },
    'D61d': { tdd: 4, distrib_vt: 4001.07, distrib_nt: 140.97, jistic_3x25: 271.04 },
};

function updateDefaults(tariff) {
    const d = TARIFF_DEFAULTS[tariff];
    if (!d) return;
    document.querySelector('[name=distrib_vt]').value = d.distrib_vt;
    document.querySelector('[name=distrib_nt]').value = d.distrib_nt;
    document.querySelector('[name=jistic_fee]').value = d.jistic_3x25;
    document.querySelector('[name=tdd]').value = d.tdd;
}

async function recalculate() {
    const form = document.getElementById('calc-form');
    const fd = new FormData(form);
    const params = new URLSearchParams();
    params.set('action', 'spot_calculator');
    for (const [k, v] of fd.entries()) params.set(k, v);

    // Aktualizuj URL (sdílení odkazu)
    const newUrl = window.location.pathname + '?' + params.toString().replace('action=spot_calculator&', '');
    window.history.replaceState({}, '', newUrl);

    try {
        const r = await fetch('/api.php?' + params.toString());
        const data = await r.json();

        if (data.error) {
            document.getElementById('breakdown').innerHTML =
                `<div style="grid-column:1/-1;color:var(--bad,#f87171);padding:1rem;text-align:center">⚠ ${data.error}</div>`;
            document.getElementById('result-stats').innerHTML = '';
            return;
        }

        renderResults(data);
    } catch (e) {
        console.error(e);
        document.getElementById('breakdown').innerHTML =
            `<div style="grid-column:1/-1;color:var(--bad,#f87171)">Chyba: ${e.message}</div>`;
    }
}

function renderResults(data) {
    const b = data.breakdown;
    const s = data.stats;
    const inp = data.inputs;

    // ─── Stat karty ───
    const period = data.period;
    const granLbl = data.granularity === '15min' ? '15min ceny' : 'hodinové ceny';
    document.getElementById('result-stats').innerHTML = `
        <div class="stat-card highlight">
            <div class="label">Měsíční náklad</div>
            <div class="value">${fmt(b.celkem_kc, 0)} Kč</div>
            <div class="sub">${period} · ${data.days_in_month} dnů · ${granLbl}</div>
        </div>
        <div class="stat-card">
            <div class="label">Průměrná cena</div>
            <div class="value">${fmt(s.avg_price_kwh)} Kč/kWh</div>
            <div class="sub">vč. distribuce a poplatků</div>
        </div>
        <div class="stat-card">
            <div class="label">Z toho silová</div>
            <div class="value">${fmt(s.avg_spot_kwh, 3)} Kč/kWh</div>
            <div class="sub">spot + poplatek obchodu</div>
        </div>
        <div class="stat-card ${s.kwh_negative > 0 ? 'good' : ''}">
            <div class="label">Spotřeba se zápornou cenou</div>
            <div class="value">${fmt(s.kwh_negative, 1)} kWh</div>
            <div class="sub">${s.kwh_negative > 0 ? 'ČEZ ti za to platí!' : 'žádné záporné okénka'}</div>
        </div>
        <div class="stat-card">
            <div class="label">Min/Max spot</div>
            <div class="value" style="font-size:1rem">${fmt(s.min_spot_mwh, 0)} / ${fmt(s.max_spot_mwh, 0)}</div>
            <div class="sub">Kč/MWh</div>
        </div>
        <div class="stat-card">
            <div class="label">Spotřeba</div>
            <div class="value">${fmt(inp.total_kwh, 0)} kWh</div>
            <div class="sub">VT ${fmt(inp.kwh_vt, 0)} + NT ${fmt(inp.kwh_nt, 0)}</div>
        </div>
    `;

    // ─── Breakdown faktury ───
    const total = b.celkem_kc;
    const pct = (v) => total > 0 ? ((v / total) * 100).toFixed(1) + ' %' : '—';

    document.getElementById('breakdown').innerHTML = `
        <div class="b-label">⚡ Silová elektřina (spot + poplatek obchodu)</div>
        <div class="b-value">${fmt(b.silova_kc, 2)} Kč</div>
        <div class="b-pct">${pct(b.silova_kc)}</div>

        <div class="b-label">📡 Distribuce VT (${fmt(inp.kwh_vt, 0)} kWh)</div>
        <div class="b-value">${fmt(b.distrib_vt_kc, 2)} Kč</div>
        <div class="b-pct">${pct(b.distrib_vt_kc)}</div>

        ${b.distrib_nt_kc > 0 ? `
        <div class="b-label">📡 Distribuce NT (${fmt(inp.kwh_nt, 0)} kWh)</div>
        <div class="b-value">${fmt(b.distrib_nt_kc, 2)} Kč</div>
        <div class="b-pct">${pct(b.distrib_nt_kc)}</div>
        ` : ''}

        <div class="b-label">🌱 POZE (${b.poze_mode === 'kwh' ? 'per kWh' : 'per A'})</div>
        <div class="b-value">${fmt(b.poze_kc, 2)} Kč</div>
        <div class="b-pct">${pct(b.poze_kc)}</div>

        <div class="b-label">🔧 Systémové služby</div>
        <div class="b-value">${fmt(b.sys_kc, 2)} Kč</div>
        <div class="b-pct">${pct(b.sys_kc)}</div>

        <div class="b-label">💰 Daň z elektřiny</div>
        <div class="b-value">${fmt(b.dan_kc, 2)} Kč</div>
        <div class="b-pct">${pct(b.dan_kc)}</div>

        <div class="b-label">📅 Stálé měsíční platby (jistič + obchodník + infra)</div>
        <div class="b-value">${fmt(b.monthly_kc, 2)} Kč</div>
        <div class="b-pct">${pct(b.monthly_kc)}</div>

        <div class="b-sep"></div>

        <div class="b-total" style="display:contents">
            <div class="b-label">CELKEM ${period} (s DPH)</div>
            <div class="b-value">${fmt(b.celkem_kc, 2)} Kč</div>
            <div class="b-pct">100 %</div>
        </div>
    `;

    // ─── Profile chart - načti denní spot průměry pro období ───
    fetch(`/api.php?action=spot_prices&granularity=${data.granularity}&from=${period}-01&to=${period}-${data.days_in_month}`)
        .then(r => r.json())
        .then(spotData => {
            const days = spotData.days || [];
            const labels = days.map(d => d.delivery_day.slice(8)); // "01"
            const minD = days.map(d => parseFloat(d.min_eur));
            const avgD = days.map(d => parseFloat(d.avg_eur));
            const maxD = days.map(d => parseFloat(d.max_eur));

            if (chart) chart.destroy();
            const ctx = document.getElementById('profile-chart');
            chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'Max', data: maxD, borderColor: 'rgba(248,113,113,0.9)', backgroundColor:'rgba(248,113,113,0.1)', fill: '+1', tension: 0.2, pointRadius: 0, borderWidth: 1.5 },
                        { label: 'Průměr', data: avgD, borderColor: 'rgba(251,191,36,1)', backgroundColor: 'rgba(251,191,36,0.15)', fill: false, tension: 0.2, pointRadius: 1, borderWidth: 2 },
                        { label: 'Min', data: minD, borderColor: 'rgba(74,222,128,0.9)', backgroundColor: 'rgba(74,222,128,0.05)', fill: false, tension: 0.2, pointRadius: 0, borderWidth: 1.5 },
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' }, tooltip: { mode: 'index', intersect: false } },
                    scales: {
                        y: { title: { display: true, text: 'EUR/MWh' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                        x: { title: { display: true, text: 'Den měsíce' }, grid: { display: false } }
                    }
                }
            });
        });
}

// Auto-recalc při načtení (pokud jsou parametry v URL nebo defaulty)
recalculate();
</script>

</body>
</html>
