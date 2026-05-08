<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use FveMonitor\Lib\Auth;

Auth::start();
if (!Auth::isLoggedIn()) {
    header('Location: admin/login.php?r=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$tab = $_GET['tab'] ?? 'today';
if (!in_array($tab, ['today', 'tomorrow', 'history'], true)) $tab = 'today';

// granularita: dt15min default pro zítřek (predikce), 15min pro dnes (realita), hour pro historii
if ($tab === 'tomorrow') {
    $defaultGran = 'dt15min';  // zítra máme jen predikci z denní aukce
} elseif ($tab === 'history') {
    $defaultGran = 'hour';
} else {
    $defaultGran = '15min';  // dnes - VDT realita
}
$granularity = $_GET['gran'] ?? $defaultGran;
if (!in_array($granularity, ['hour', '15min', 'dt15min', 'compare'], true)) $granularity = $defaultGran;

// Picker konkrétního dne (jen pro tab today/tomorrow - když user chce procházet)
$selectedDay = $_GET['day'] ?? null;
if ($selectedDay && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay)) $selectedDay = null;

// Datumové meze pro picker (15min: od 1.10.2024, hour: od 1.1.2024)
$minDay = $granularity === '15min' ? '2024-10-01' : '2024-01-01';
$maxDay = date('Y-m-d', strtotime('+1 day'));

$pageTitle    = 'Spotové ceny — FVE Monitor';
$pageHeading  = '⚡ Spotové ceny elektřiny';
$activePage   = 'spot';
$includeChart = true;
require __DIR__ . '/_app_head.php';
?>
<body>
<?php require __DIR__ . '/_topbar.php'; ?>

<main class="container" style="max-width:1400px;margin:1rem auto;padding:0 1rem">

    <!-- Tab navigation -->
    <div class="spot-tabs">
        <a href="?tab=today" class="spot-tab <?= $tab === 'today' ? 'active' : '' ?>">📅 Dnes</a>
        <a href="?tab=tomorrow" class="spot-tab <?= $tab === 'tomorrow' ? 'active' : '' ?>">🔜 Zítra</a>
        <a href="?tab=history" class="spot-tab <?= $tab === 'history' ? 'active' : '' ?>">📊 Historie</a>
        <div class="spot-gran">
            <?php
            $rangeQs = isset($_GET['from']) ? '&from='.htmlspecialchars($_GET['from']).'&to='.htmlspecialchars($_GET['to']) : '';
            $dayQs = isset($_GET['day']) ? '&day='.htmlspecialchars($_GET['day']) : '';
            ?>
            <a href="?tab=<?= $tab ?>&gran=dt15min<?= $rangeQs.$dayQs ?>" class="gran-btn <?= $granularity === 'dt15min' ? 'active' : '' ?>" title="Denní trh - predikce z aukce, 15min produkty">📈 DT predikce</a>
            <a href="?tab=<?= $tab ?>&gran=15min<?= $rangeQs.$dayQs ?>" class="gran-btn <?= $granularity === '15min' ? 'active' : '' ?>" title="Vnitrodenní trh - reálné obchody 15min">⚡ VDT realita</a>
            <a href="?tab=<?= $tab ?>&gran=compare<?= $rangeQs.$dayQs ?>" class="gran-btn <?= $granularity === 'compare' ? 'active' : '' ?>" title="DT predikce vs VDT realita">🔀 Srovnání</a>
            <a href="?tab=<?= $tab ?>&gran=hour<?= $rangeQs.$dayQs ?>" class="gran-btn <?= $granularity === 'hour' ? 'active' : '' ?>" title="Hodinová DT cena (starší formát)">🕐 1 hod</a>
        </div>
        <?php
$sourceLabel = match($granularity) {
    'dt15min' => 'denní trh 15min (predikce)',
    '15min'   => 'vnitrodenní trh 15min (realita)',
    'compare' => 'DT predikce + VDT realita',
    default   => 'denní trh hodinový',
};
?>
        <span class="spot-source">Zdroj: OTE-CR · <?= $sourceLabel ?> · kurz ČNB</span>
    </div>

    <?php if ($tab !== 'history'): ?>
    <!-- Picker dne -->
    <div class="day-picker">
        <?php
        $current = $selectedDay ?: ($tab === 'tomorrow' ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'));
        $prev = date('Y-m-d', strtotime($current . ' -1 day'));
        $next = date('Y-m-d', strtotime($current . ' +1 day'));
        $isToday = $current === date('Y-m-d');
        $isTomorrow = $current === date('Y-m-d', strtotime('+1 day'));
        $weekday = ['neděle','pondělí','úterý','středa','čtvrtek','pátek','sobota'][(int)date('w', strtotime($current))];
        ?>
        <a href="?tab=<?= $tab ?>&gran=<?= $granularity ?>&day=<?= $prev ?>" class="day-nav" title="Předchozí den">‹</a>

        <div class="day-current">
            <input type="date" id="day-input" value="<?= htmlspecialchars($current) ?>"
                   min="<?= $minDay ?>" max="<?= $maxDay ?>"
                   onchange="window.location.href='?tab=<?= $tab ?>&gran=<?= $granularity ?>&day=' + this.value">
            <span class="day-meta"><?= $weekday ?> · <?= date('j.n.Y', strtotime($current)) ?></span>
        </div>

        <a href="?tab=<?= $tab ?>&gran=<?= $granularity ?>&day=<?= $next ?>" class="day-nav" title="Následující den">›</a>

        <div class="day-shortcuts">
            <a href="?tab=today&gran=<?= $granularity ?>" class="day-quick <?= $isToday ? 'active' : '' ?>">Dnes</a>
            <a href="?tab=tomorrow&gran=<?= $granularity ?>" class="day-quick <?= $isTomorrow ? 'active' : '' ?>">Zítra</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistiky karty -->
    <div id="stats-cards" class="stats-grid"></div>

    <!-- Graf -->
    <div class="card" style="margin-top:1rem">
        <div id="chart-title" style="font-weight:600;margin-bottom:0.5rem;color:var(--text)"></div>
        <div style="position:relative;height:380px">
            <canvas id="spot-chart"></canvas>
        </div>
    </div>

    <!-- Filtr pro historii -->
    <?php if ($tab === 'history'): ?>
    <div class="card" style="margin-top:1rem">
        <form method="get" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="tab" value="history">
            <div>
                <label style="display:block;font-size:0.85rem;color:var(--text-dim);margin-bottom:4px">Od</label>
                <input type="date" name="from" value="<?= htmlspecialchars($_GET['from'] ?? date('Y-m-d', strtotime('-30 days'))) ?>" style="padding:6px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;color:var(--text)">
            </div>
            <div>
                <label style="display:block;font-size:0.85rem;color:var(--text-dim);margin-bottom:4px">Do</label>
                <input type="date" name="to" value="<?= htmlspecialchars($_GET['to'] ?? date('Y-m-d')) ?>" style="padding:6px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;color:var(--text)">
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button type="submit" style="padding:6px 14px;background:var(--accent);color:#000;border:none;border-radius:4px;font-weight:600;cursor:pointer">Zobrazit</button>
                <a href="?tab=history&from=<?= date('Y-m-d', strtotime('-7 days')) ?>&to=<?= date('Y-m-d') ?>" class="quick-range">7 dní</a>
                <a href="?tab=history&from=<?= date('Y-m-d', strtotime('-30 days')) ?>&to=<?= date('Y-m-d') ?>" class="quick-range">30 dní</a>
                <a href="?tab=history&from=<?= date('Y-m-d', strtotime('-90 days')) ?>&to=<?= date('Y-m-d') ?>" class="quick-range">90 dní</a>
                <a href="?tab=history&from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="quick-range">YTD</a>
                <a href="?tab=history&from=2024-01-01&to=<?= date('Y-m-d') ?>" class="quick-range">Vše</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Tabulka -->
    <div class="card" style="margin-top:1rem">
        <div id="table-title" style="font-weight:600;margin-bottom:0.5rem;color:var(--text)"></div>
        <div style="overflow-x:auto">
            <table id="spot-table" class="data-table">
                <thead id="spot-thead"></thead>
                <tbody id="spot-tbody"></tbody>
            </table>
        </div>
    </div>

</main>

<style>
.spot-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 1.25rem;
    align-items: center;
    flex-wrap: wrap;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border);
    position: relative;
}
.spot-tab {
    padding: 12px 22px;
    background: transparent;
    color: var(--text-dim);
    text-decoration: none;
    border-radius: 8px;
    border: 1.5px solid var(--border);
    font-weight: 500;
    font-size: 1rem;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.spot-tab:hover {
    background: var(--surface-2);
    color: var(--text);
    border-color: var(--text-dim);
    transform: translateY(-1px);
}
.spot-tab.active {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
    font-weight: 700;
    box-shadow: 0 4px 12px rgba(251, 191, 36, 0.25);
    transform: translateY(-1px);
}
.spot-tab.active:hover {
    background: var(--accent);
    color: #000;
    transform: translateY(-1px);
}
.spot-gran {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
    padding: 0 8px 8px;
}
.spot-gran label {
    color: var(--text-dim);
    font-size: 0.78rem;
}
.gran-btn {
    padding: 4px 10px;
    background: var(--surface-2);
    color: var(--text-dim);
    text-decoration: none;
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 0.78rem;
}
.gran-btn:hover { color: var(--text); }
.gran-btn.active {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
    font-weight: 600;
}
.spot-source {
    margin-left: 0;
    color: var(--text-dim);
    font-size: 0.78rem;
    padding: 0 8px 8px;
}

.day-picker {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    padding: 0.6rem 0.8rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    flex-wrap: wrap;
}
.day-nav {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-2);
    color: var(--text);
    text-decoration: none;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 1.4rem;
    font-weight: 600;
    transition: all 0.15s;
}
.day-nav:hover {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
}
.day-current {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 180px;
}
.day-current input[type="date"] {
    padding: 6px 10px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    font-size: 0.95rem;
    font-weight: 600;
    color-scheme: dark;
}
.day-meta {
    font-size: 0.78rem;
    color: var(--text-dim);
    padding-left: 4px;
}
.day-shortcuts {
    display: flex;
    gap: 4px;
    margin-left: auto;
}
.day-quick {
    padding: 6px 12px;
    background: var(--surface-2);
    color: var(--text-dim);
    text-decoration: none;
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 0.85rem;
    transition: all 0.15s;
}
.day-quick:hover { color: var(--text); }
.day-quick.active {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
    font-weight: 600;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}
.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.9rem 1rem;
}
.stat-card .label {
    font-size: 0.75rem;
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.stat-card .value {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--text);
}
.stat-card .sub {
    font-size: 0.75rem;
    color: var(--text-dim);
    margin-top: 2px;
}
.stat-card.min .value { color: var(--good, #4ade80); }
.stat-card.max .value { color: var(--bad, #f87171); }
.stat-card.avg .value { color: var(--accent, #fbbf24); }

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.data-table th {
    background: var(--surface-2);
    padding: 8px 10px;
    text-align: right;
    color: var(--text-dim);
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
}
.data-table th:first-child { text-align: left; }
.data-table td {
    padding: 6px 10px;
    border-bottom: 1px solid var(--border);
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.data-table td:first-child { text-align: left; font-weight: 500; }
.data-table tbody tr:hover { background: var(--surface-2); }
.data-table tr.is-min td { background: rgba(74, 222, 128, 0.08); }
.data-table tr.is-max td { background: rgba(248, 113, 113, 0.08); }
.cell-neg { color: var(--good, #4ade80); font-weight: 600; }
.cell-high { color: var(--bad, #f87171); font-weight: 600; }
.empty-msg {
    padding: 2rem;
    text-align: center;
    color: var(--text-dim);
}

.quick-range {
    padding: 6px 12px;
    background: var(--surface-2);
    color: var(--text-dim);
    text-decoration: none;
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 0.85rem;
    transition: all 0.15s;
}
.quick-range:hover {
    background: var(--surface);
    color: var(--accent);
    border-color: var(--accent);
}

/* ─── Divergent bar (compare tab) ─── */
.diff-bar-th { min-width: 140px; text-align: center !important; }
.diff-bar-td { padding: 4px 6px !important; min-width: 140px; }
.diff-bar-cell {
    width: 100%;
    height: 18px;
    display: flex;
    align-items: center;
}
.diff-bar-track {
    position: relative;
    width: 100%;
    height: 14px;
    background: var(--surface-2);
    border-radius: 2px;
    overflow: visible;
}
.diff-bar-center {
    position: absolute;
    left: 50%;
    top: -2px;
    bottom: -2px;
    width: 1.5px;
    background: var(--text-dim);
    opacity: 0.5;
    transform: translateX(-50%);
}
.diff-bar-fill {
    position: absolute;
    top: 0;
    bottom: 0;
    border-radius: 2px;
    transition: width 0.2s ease;
}
.diff-bar-pos { left: 50%; }
.diff-bar-neg { /* right je inline (50%) */ }
</style>

<script>
const TAB = <?= json_encode($tab) ?>;
const FROM = <?= json_encode($_GET['from'] ?? null) ?>;
const TO = <?= json_encode($_GET['to'] ?? null) ?>;
const GRAN = <?= json_encode($granularity) ?>;
const SELECTED_DAY = <?= json_encode($selectedDay) ?>;

// ─── Helpery ───
const fmt = (v, dec = 2) => {
    if (v === null || v === undefined) return '—';
    return Number(v).toLocaleString('cs-CZ', { minimumFractionDigits: dec, maximumFractionDigits: dec });
};
const fmtDate = (s) => {
    const d = new Date(s + 'T00:00:00');
    return d.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric' });
};
// Normalizuje data z hourly/15min/dt15min API do společného formátu {label, eur, czk}
function normalizeRows(rows, gran) {
    if (gran === '15min') {
        return rows.map(r => ({
            label: r.time_from.slice(0, 5),
            period: r.period,
            eur: parseFloat(r.price_avg_eur),
            czk: parseFloat(r.price_avg_czk),
            eur_min: r.price_min_eur !== null ? parseFloat(r.price_min_eur) : null,
            eur_max: r.price_max_eur !== null ? parseFloat(r.price_max_eur) : null,
            volume: r.volume_mwh !== null ? parseFloat(r.volume_mwh) : null,
        }));
    }
    if (gran === 'dt15min') {
        return rows.map(r => ({
            label: r.time_from.slice(0, 5),
            period: r.period,
            eur: parseFloat(r.price_15min_eur),
            czk: parseFloat(r.price_15min_czk),
            eur_60: r.price_60min_eur !== null ? parseFloat(r.price_60min_eur) : null,
            volume: r.volume_mwh !== null ? parseFloat(r.volume_mwh) : null,
            saldo: r.saldo_mwh !== null ? parseFloat(r.saldo_mwh) : null,
        }));
    }
    return rows.map(r => ({
        label: String(r.hour).padStart(2, '0') + ':00',
        hour: r.hour,
        eur: parseFloat(r.price_eur_mwh),
        czk: parseFloat(r.price_czk_mwh),
    }));
}

const cellClass = (eur, min, max) => {
    if (eur === min && min !== max) return 'is-min';
    if (eur === max && min !== max) return 'is-max';
    return '';
};
const priceColor = (eur) => {
    if (eur < 0) return 'cell-neg';
    if (eur > 200) return 'cell-high';
    return '';
};

let chart = null;

// ─── Render: stat cards ───
function renderStats(stats, label = '', context = '') {
    const container = document.getElementById('stats-cards');
    if (!stats || stats.count === 0) {
        container.innerHTML = '<div class="empty-msg">Žádná data</div>';
        return;
    }
    container.innerHTML = `
        <div class="stat-card min">
            <div class="label">Min ${label}</div>
            <div class="value">${fmt(stats.min_czk / 1000, 2)} Kč/kWh</div>
            <div class="sub">${fmt(stats.min_czk, 0)} Kč/MWh · ${fmt(stats.min_eur)} €/MWh</div>
        </div>
        <div class="stat-card avg">
            <div class="label">Průměr ${label}</div>
            <div class="value">${fmt(stats.avg_czk / 1000, 2)} Kč/kWh</div>
            <div class="sub">${fmt(stats.avg_czk, 0)} Kč/MWh · ${fmt(stats.avg_eur)} €/MWh</div>
        </div>
        <div class="stat-card max">
            <div class="label">Max ${label}</div>
            <div class="value">${fmt(stats.max_czk / 1000, 2)} Kč/kWh</div>
            <div class="sub">${fmt(stats.max_czk, 0)} Kč/MWh · ${fmt(stats.max_eur)} €/MWh</div>
        </div>
        <div class="stat-card">
            <div class="label">Vzorků</div>
            <div class="value">${stats.count}</div>
            <div class="sub">${stats.count >= 24 ? Math.round(stats.count / 24) + ' dní' : 'hodin'}</div>
        </div>
    `;
}

// ─── Render: hodinový graf (sloupcový) ───
function renderHourlyChart(rows, title) {
    const labels = rows.map(r => r.label);
    const eur = rows.map(r => r.eur);
    const czk = rows.map(r => r.czk);
    const is15 = GRAN === '15min';

    document.getElementById('chart-title').textContent = title;

    const ctx = document.getElementById('spot-chart');
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Kč/MWh',
                data: czk,
                backgroundColor: czk.map(v => {
                    if (v < 0) return 'rgba(74, 222, 128, 0.85)';
                    if (v > 3500) return 'rgba(248, 113, 113, 0.85)';
                    return 'rgba(251, 191, 36, 0.75)';
                }),
                borderColor: 'rgba(251, 191, 36, 1)',
                borderWidth: 0.5,
                barPercentage: is15 ? 1.0 : 0.85,
                categoryPercentage: is15 ? 1.0 : 0.85,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            const r = rows[items[0].dataIndex];
                            return is15 ? `Period ${r.period} · ${r.label}` : `${r.label}`;
                        },
                        label: (ctx) => {
                            const r = rows[ctx.dataIndex];
                            const lines = [
                                `${fmt(r.czk, 0)} Kč/MWh  (${fmt(r.czk / 1000, 3)} Kč/kWh)`,
                                `${fmt(r.eur)} €/MWh`,
                            ];
                            if (is15 && r.eur_min !== null && r.eur_max !== null) {
                                lines.push(`Rozpětí: ${fmt(r.eur_min)} – ${fmt(r.eur_max)} €/MWh`);
                            }
                            if (is15 && r.volume !== null) {
                                lines.push(`Objem: ${fmt(r.volume, 1)} MWh`);
                            }
                            return lines;
                        }
                    }
                }
            },
            scales: {
                y: {
                    title: { display: true, text: 'Kč/MWh' },
                    grid: { color: 'rgba(255,255,255,0.05)' }
                },
                x: {
                    grid: { display: false },
                    ticks: is15 ? {
                        autoSkip: true,
                        maxTicksLimit: 24,
                        callback: function(val, idx) {
                            // Zobraz jen každý 4. tick (= celé hodiny)
                            const lbl = this.getLabelForValue(val);
                            return lbl && lbl.endsWith(':00') ? lbl : '';
                        }
                    } : {}
                }
            }
        }
    });
}

// ─── Render: hodinová tabulka ───
function renderHourlyTable(rows, title) {
    document.getElementById('table-title').textContent = title;
    const is15 = GRAN === '15min';
    const colInterval = is15 ? 'Interval' : 'Hodina';
    const extraCols = is15 ? '<th>Min €/MWh</th><th>Max €/MWh</th><th>Objem MWh</th>' : '';
    const colspan = is15 ? 7 : 4;

    document.getElementById('spot-thead').innerHTML = `
        <tr>
            <th>${colInterval}</th>
            <th>Kč/kWh</th>
            <th>Kč/MWh</th>
            <th>EUR/MWh</th>
            ${extraCols}
        </tr>
    `;
    if (!rows || rows.length === 0) {
        document.getElementById('spot-tbody').innerHTML = `<tr><td colspan="${colspan}" class="empty-msg">Žádná data — možná ještě nebyla publikována</td></tr>`;
        return;
    }
    const czk = rows.map(r => r.czk);
    const min = Math.min(...czk), max = Math.max(...czk);
    document.getElementById('spot-tbody').innerHTML = rows.map(r => {
        let intervalLabel;
        if (is15) {
            // 15min: time_from "00:15" → end "00:30"
            const [h, m] = r.label.split(':').map(Number);
            const endM = (m + 15) % 60;
            const endH = (h + Math.floor((m + 15) / 60)) % 24;
            intervalLabel = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} – ${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`;
        } else {
            intervalLabel = `${String(r.hour).padStart(2,'0')}:00 – ${String((r.hour + 1) % 24).padStart(2,'0')}:00`;
        }

        const extra = is15
            ? `<td>${fmt(r.eur_min)}</td><td>${fmt(r.eur_max)}</td><td>${fmt(r.volume, 1)}</td>`
            : '';

        return `
            <tr class="${cellClass(r.czk, min, max)}">
                <td>${intervalLabel}</td>
                <td><strong>${fmt(r.czk / 1000, 3)}</strong></td>
                <td class="${priceColor(r.eur)}">${fmt(r.czk, 0)}</td>
                <td>${fmt(r.eur)}</td>
                ${extra}
            </tr>
        `;
    }).join('');
}

// ─── Render: denní graf (sloupcový s min/avg/max) ───
function renderDailyChart(days, title) {
    const labels = days.map(d => fmtDate(d.delivery_day));
    const minD = days.map(d => parseFloat(d.min_czk));
    const avgD = days.map(d => parseFloat(d.avg_czk));
    const maxD = days.map(d => parseFloat(d.max_czk));

    document.getElementById('chart-title').textContent = title;

    const ctx = document.getElementById('spot-chart');
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Max',
                    data: maxD,
                    borderColor: 'rgba(248, 113, 113, 0.9)',
                    backgroundColor: 'rgba(248, 113, 113, 0.1)',
                    fill: '+1',
                    tension: 0.2,
                    pointRadius: 0,
                    borderWidth: 1.5,
                },
                {
                    label: 'Průměr',
                    data: avgD,
                    borderColor: 'rgba(251, 191, 36, 1)',
                    backgroundColor: 'rgba(251, 191, 36, 0.15)',
                    fill: false,
                    tension: 0.2,
                    pointRadius: 1,
                    borderWidth: 2,
                },
                {
                    label: 'Min',
                    data: minD,
                    borderColor: 'rgba(74, 222, 128, 0.9)',
                    backgroundColor: 'rgba(74, 222, 128, 0.05)',
                    fill: false,
                    tension: 0.2,
                    pointRadius: 0,
                    borderWidth: 1.5,
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.y, 0)} Kč/MWh (${fmt(ctx.parsed.y / 1000, 3)} Kč/kWh)`
                    }
                }
            },
            scales: {
                y: {
                    title: { display: true, text: 'Kč/MWh' },
                    grid: { color: 'rgba(255,255,255,0.05)' }
                },
                x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 0, autoSkip: true, maxTicksLimit: 20 } }
            }
        }
    });
}

// ─── Render: denní tabulka ───
function renderDailyTable(days, title) {
    document.getElementById('table-title').textContent = title;
    document.getElementById('spot-thead').innerHTML = `
        <tr>
            <th>Den</th>
            <th>Min Kč/kWh</th>
            <th>Ø Kč/kWh</th>
            <th>Max Kč/kWh</th>
            <th>Ø Kč/MWh</th>
            <th>Ø EUR/MWh</th>
            <th>Hodin</th>
        </tr>
    `;
    if (!days || days.length === 0) {
        document.getElementById('spot-tbody').innerHTML = '<tr><td colspan="7" class="empty-msg">Žádná data v tomto rozsahu</td></tr>';
        return;
    }
    // Reverse - novější nahoře
    const sorted = [...days].reverse();
    document.getElementById('spot-tbody').innerHTML = sorted.map(d => `
        <tr>
            <td>${fmtDate(d.delivery_day)}</td>
            <td class="${priceColor(parseFloat(d.min_eur))}">${fmt(parseFloat(d.min_czk) / 1000, 3)}</td>
            <td><strong>${fmt(parseFloat(d.avg_czk) / 1000, 3)}</strong></td>
            <td class="${priceColor(parseFloat(d.max_eur))}">${fmt(parseFloat(d.max_czk) / 1000, 3)}</td>
            <td>${fmt(d.avg_czk, 0)}</td>
            <td>${fmt(d.avg_eur)}</td>
            <td>${d.hours}</td>
        </tr>
    `).join('');
}

// ─── Compare mode: DT predikce vs VDT realita ───
function renderCompareMode(data) {
    const periods = data.periods || [];
    const stats = data.stats || {};
    const day = data.day;

    // ─── Stat karty ───
    const container = document.getElementById('stats-cards');
    if (!periods.length) {
        container.innerHTML = '<div class="empty-msg" style="grid-column:1/-1">Žádná data pro srovnání</div>';
        return;
    }

    const avgDiffEur = stats.avg_diff_eur;
    const avgDiffPct = stats.avg_diff_pct;
    const isOver = (avgDiffEur || 0) > 0;
    const diffColor = isOver ? 'bad' : 'good';
    const diffArrow = isOver ? '↑' : '↓';

    // Spočítej Kč ekvivalenty z EUR (kurz vezmem z prvního period s rate)
    const rate = (() => {
        for (const p of periods) {
            if (p.dt_eur !== null && p.dt_czk !== null && p.dt_eur !== 0) {
                return parseFloat(p.dt_czk) / parseFloat(p.dt_eur);
            }
        }
        return 25.0;
    })();
    const avgDiffCzk = avgDiffEur !== null ? avgDiffEur * rate : null;
    const maxOverCzk = stats.max_over_eur !== null ? stats.max_over_eur * rate : null;
    const maxUnderCzk = stats.max_under_eur !== null ? stats.max_under_eur * rate : null;

    container.innerHTML = `
        <div class="stat-card">
            <div class="label">Datum srovnání</div>
            <div class="value" style="font-size:1rem">${fmtDate(day)}</div>
            <div class="sub">${stats.periods_with_data}/${stats.count} period s daty</div>
        </div>
        <div class="stat-card ${diffColor}">
            <div class="label">Průměrná odchylka</div>
            <div class="value">${diffArrow} ${fmt(Math.abs(avgDiffCzk || 0), 0)} Kč/MWh</div>
            <div class="sub">${avgDiffPct !== null ? (isOver ? '+' : '') + fmt(avgDiffPct, 1) + ' % · ' + (isOver ? '+' : '') + fmt(avgDiffEur, 2) + ' €' : '—'}</div>
        </div>
        <div class="stat-card bad">
            <div class="label">Max nad predikci</div>
            <div class="value">+${fmt(maxOverCzk, 0)} Kč/MWh</div>
            <div class="sub">+${fmt(stats.max_over_eur, 2)} €/MWh · ${stats.periods_over} period</div>
        </div>
        <div class="stat-card good">
            <div class="label">Max pod predikci</div>
            <div class="value">${fmt(maxUnderCzk, 0)} Kč/MWh</div>
            <div class="sub">${fmt(stats.max_under_eur, 2)} €/MWh · ${stats.periods_under} period</div>
        </div>
    `;

    // ─── Graf - 2 sady sloupců (DT modrá, VDT oranžová) ───
    const labels = periods.map(p => p.time_from.slice(0, 5));
    const dtData  = periods.map(p => p.dt_czk !== null ? parseFloat(p.dt_czk) : null);
    const vdtData = periods.map(p => p.vdt_czk !== null ? parseFloat(p.vdt_czk) : null);

    // Třetí dataset: divergent bar (rozdíl Kč/MWh) - na sekundární ose
    const diffData = periods.map(p => p.diff_czk !== null ? parseFloat(p.diff_czk) : null);

    // Barvy pro divergent bar - per period
    const diffColors = diffData.map(v => {
        if (v === null) return 'rgba(120, 120, 120, 0.3)';
        return v > 0
            ? 'rgba(248, 113, 113, 0.85)'   // červená (nad predikci)
            : 'rgba(74, 222, 128, 0.85)';   // zelená (pod predikci)
    });
    const diffBorders = diffData.map(v => {
        if (v === null) return 'rgba(120, 120, 120, 0.5)';
        return v > 0
            ? 'rgba(248, 113, 113, 1)'
            : 'rgba(74, 222, 128, 1)';
    });

    document.getElementById('chart-title').textContent =
        `🔀 Predikce vs realita — ${fmtDate(day)} (96 period)`;

    const ctx = document.getElementById('spot-chart');
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: '📈 DT predikce',
                    data: dtData,
                    backgroundColor: 'rgba(96, 165, 250, 0.75)',
                    borderColor: 'rgba(96, 165, 250, 1)',
                    borderWidth: 0.5,
                    barPercentage: 1.0,
                    categoryPercentage: 0.85,
                    yAxisID: 'y',
                    order: 2,
                },
                {
                    label: '⚡ VDT realita',
                    data: vdtData,
                    backgroundColor: 'rgba(251, 191, 36, 0.85)',
                    borderColor: 'rgba(251, 191, 36, 1)',
                    borderWidth: 0.5,
                    barPercentage: 1.0,
                    categoryPercentage: 0.85,
                    yAxisID: 'y',
                    order: 2,
                },
                {
                    label: '🔀 Odchylka (Kč/MWh)',
                    data: diffData,
                    backgroundColor: diffColors,
                    borderColor: diffBorders,
                    borderWidth: 0.5,
                    barPercentage: 1.0,
                    categoryPercentage: 0.6,
                    yAxisID: 'yDiff',
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        title: (items) => `Period ${periods[items[0].dataIndex].period} · ${labels[items[0].dataIndex]}`,
                        afterBody: (items) => {
                            const p = periods[items[0].dataIndex];
                            if (p.diff_eur === null) return '';
                            const sign = p.diff_eur > 0 ? '+' : '';
                            return [
                                `Rozdíl: ${sign}${fmt(p.diff_czk, 0)} Kč/MWh`,
                                `         ${sign}${fmt(p.diff_eur)} €/MWh (${sign}${fmt(p.diff_pct, 1)} %)`
                            ];
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    title: { display: true, text: 'Cena Kč/MWh' },
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    // Dynamický rozsah: max ceny + 10%, min = -max(odchylka)
                    suggestedMax: Math.max(...dtData.filter(v => v !== null), ...vdtData.filter(v => v !== null)) * 1.05,
                    suggestedMin: -Math.max(...diffData.filter(v => v !== null).map(v => Math.abs(v))) * 1.2,
                },
                yDiff: {
                    type: 'linear',
                    position: 'right',
                    title: { display: true, text: 'Odchylka Kč/MWh', color: 'rgba(248, 113, 113, 0.9)' },
                    grid: { drawOnChartArea: false },
                    ticks: {
                        callback: (val) => (val > 0 ? '+' : '') + val
                    },
                    // Stejný rozsah jako levá osa, ale přepočítaný na škálu odchylky
                    // Nula musí být na stejné Y pozici jako u levé osy
                    suggestedMax: Math.max(...diffData.filter(v => v !== null)) * 1.2,
                    suggestedMin: Math.min(...diffData.filter(v => v !== null)) * 1.2,
                    afterFit: function(scaleInstance) {
                        // Synchronizuj nulu: poměr maxY/minY musí sedět s levou osou
                        const leftScale = scaleInstance.chart.scales.y;
                        if (!leftScale) return;
                        const leftRange = leftScale.max - leftScale.min;
                        const leftZeroRatio = leftScale.max / leftRange;  // kde leží 0 odshora (0..1)

                        // Pro pravou osu chceme stejný poměr
                        const rightMax = Math.max(...diffData.filter(v => v !== null), 1) * 1.2;
                        // rightMax / (rightMax - rightMin) = leftZeroRatio
                        // → rightMin = rightMax - rightMax/leftZeroRatio
                        const rightMin = rightMax - (rightMax / leftZeroRatio);
                        scaleInstance.options.min = rightMin;
                        scaleInstance.options.max = rightMax;
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        autoSkip: true,
                        maxTicksLimit: 24,
                        callback: function(val) {
                            const lbl = this.getLabelForValue(val);
                            return lbl && lbl.endsWith(':00') ? lbl : '';
                        }
                    }
                }
            }
        }
    });

    // ─── Tabulka ───
    document.getElementById('table-title').textContent =
        `Detail period — ${fmtDate(day)}`;
    document.getElementById('spot-thead').innerHTML = `
        <tr>
            <th>Period</th>
            <th>Interval</th>
            <th>📈 DT predikce<br><span style="font-weight:400;text-transform:none;color:var(--text-dim)">Kč/MWh · €</span></th>
            <th>⚡ VDT realita<br><span style="font-weight:400;text-transform:none;color:var(--text-dim)">Kč/MWh · €</span></th>
            <th>Rozdíl<br><span style="font-weight:400;text-transform:none;color:var(--text-dim)">Kč/MWh</span></th>
            <th>Rozdíl %</th>
            <th class="diff-bar-th">Odchylka</th>
            <th>Objem MWh</th>
        </tr>
    `;

    // Spočítej max absolutní odchylku pro škálování barů (společný range pro všechny řádky)
    const allDiffs = periods.map(p => p.diff_eur !== null ? Math.abs(parseFloat(p.diff_eur)) : 0);
    const maxAbsDiff = Math.max(...allDiffs, 1); // min 1 ať se nedělí nulou

    document.getElementById('spot-tbody').innerHTML = periods.map(p => {
        const diffEur = p.diff_eur !== null ? parseFloat(p.diff_eur) : null;
        const diffPct = p.diff_pct !== null ? parseFloat(p.diff_pct) : null;
        let diffClass = '';
        if (diffEur !== null) {
            if (Math.abs(diffEur) > 30) diffClass = diffEur > 0 ? 'cell-high' : 'cell-neg';
        }
        const sign = (v) => v > 0 ? '+' : '';

        // Divergent bar: 0% = uprostřed, kladné jde doprava (červené), záporné doleva (zelené)
        let diffBar = '<span style="color:var(--text-dim)">—</span>';
        if (diffEur !== null) {
            const ratio = Math.abs(diffEur) / maxAbsDiff;  // 0..1
            const widthPct = (ratio * 50).toFixed(1);  // 0..50% (polovina šířky pro každou stranu)
            const isPositive = diffEur > 0;
            const barColor = isPositive ? 'rgba(248, 113, 113, 0.85)' : 'rgba(74, 222, 128, 0.85)';

            if (isPositive) {
                // Kladné = doprava od středu
                diffBar = `
                    <div class="diff-bar-cell">
                        <div class="diff-bar-track">
                            <div class="diff-bar-center"></div>
                            <div class="diff-bar-fill diff-bar-pos" style="width:${widthPct}%; background:${barColor}"></div>
                        </div>
                    </div>
                `;
            } else {
                // Záporné = doleva od středu
                diffBar = `
                    <div class="diff-bar-cell">
                        <div class="diff-bar-track">
                            <div class="diff-bar-center"></div>
                            <div class="diff-bar-fill diff-bar-neg" style="width:${widthPct}%; background:${barColor}; right:50%"></div>
                        </div>
                    </div>
                `;
            }
        }

        // Kč hodnoty
        const dtCzk = p.dt_czk !== null ? parseFloat(p.dt_czk) : null;
        const vdtCzk = p.vdt_czk !== null ? parseFloat(p.vdt_czk) : null;
        const diffCzk = (dtCzk !== null && vdtCzk !== null) ? (vdtCzk - dtCzk) : null;

        return `
            <tr>
                <td>${p.period}</td>
                <td>${p.time_from.slice(0,5)}</td>
                <td>${dtCzk !== null ? '<strong>' + fmt(dtCzk, 0) + '</strong> <span style="color:var(--text-dim);font-size:0.78em">' + fmt(p.dt_eur) + ' €</span>' : '—'}</td>
                <td>${vdtCzk !== null ? '<strong>' + fmt(vdtCzk, 0) + '</strong> <span style="color:var(--text-dim);font-size:0.78em">' + fmt(p.vdt_eur) + ' €</span>' : '<span style="color:var(--text-dim)">čeká se</span>'}</td>
                <td class="${diffClass}">${diffCzk !== null ? sign(diffCzk) + fmt(diffCzk, 0) : '—'}</td>
                <td class="${diffClass}">${diffPct !== null ? sign(diffPct) + fmt(diffPct, 1) + ' %' : '—'}</td>
                <td class="diff-bar-td">${diffBar}</td>
                <td>${p.vdt_volume !== null ? fmt(p.vdt_volume, 1) : '—'}</td>
            </tr>
        `;
    }).join('');
}

// ─── Načtení dat dle tabu ───
async function loadData() {
    try {
        let url = '/api.php?action=spot_prices&granularity=' + GRAN;
        if (TAB === 'history') {
            url += '&from=' + (FROM || new Date(Date.now() - 30*86400000).toISOString().slice(0,10))
                 + '&to=' + (TO || new Date().toISOString().slice(0,10));
        } else if (SELECTED_DAY) {
            // Picker - konkrétní den
            url += '&day=' + SELECTED_DAY;
        } else if (GRAN === 'compare') {
            // Compare - default dnes
            url += '&day=' + new Date().toISOString().slice(0,10);
        }

        const r = await fetch(url);
        const data = await r.json();

        // Compare mode - speciální render
        if (data.mode === 'compare') {
            renderCompareMode(data);
            return;
        }

        // DT 15min: pokud konkrétní den
        if (data.mode === 'day_dt15min') {
            const rows = normalizeRows(data.periods || [], GRAN);
            renderStats(data.stats, '· ' + fmtDate(data.day), 'predikce');
            renderHourlyChart(rows, `📈 DT predikce — ${fmtDate(data.day)}`);
            renderHourlyTable(rows, `Tabulka cen — ${fmtDate(data.day)}`);
            return;
        }

        // DT 15min default: multi-day (dnes + zítra + pozítří)
        if (data.mode === 'multi_day_dt15min') {
            // Zobraz to k jaký tab byl vybrán
            let dayKey, dayDate, available;
            if (TAB === 'tomorrow') {
                dayKey = 'tomorrow';
                dayDate = data.tomorrow_date;
                available = data.tomorrow_available;
            } else {
                dayKey = 'today';
                dayDate = data.today_date;
                available = data.today.length > 0;
            }
            const rawRows = data[dayKey] || [];
            if (rawRows.length === 0) {
                document.getElementById('stats-cards').innerHTML =
                    '<div class="empty-msg" style="grid-column:1/-1;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:1.5rem">' +
                    '⏳ Predikce pro ' + fmtDate(dayDate) + ' zatím nepublikována.<br>' +
                    '<span style="font-size:0.85rem">OTE publikuje DT 15min D-1 v 14:00.</span></div>';
                document.getElementById('chart-title').textContent = '';
                renderHourlyTable([], `Tabulka cen — ${fmtDate(dayDate)}`);
                return;
            }
            const rows = normalizeRows(rawRows, GRAN);
            renderStats(data[dayKey + '_stats'], '· ' + fmtDate(dayDate), 'predikce');
            renderHourlyChart(rows, `📈 DT predikce — ${fmtDate(dayDate)}`);
            renderHourlyTable(rows, `Tabulka cen — ${fmtDate(dayDate)}`);
            return;
        }

        // Pokud máme konkrétní den (mode=day nebo day_15min), zobraz ho jako "today"
        if (data.mode === 'day' || data.mode === 'day_15min') {
            const rawRows = data.hours || data.periods || [];
            const rows = normalizeRows(rawRows, GRAN);
            renderStats(data.stats, '· ' + fmtDate(data.day));
            const titlePart = GRAN === '15min' ? '15min ceny' : 'Hodinové ceny';
            renderHourlyChart(rows, `${titlePart} — ${fmtDate(data.day)}`);
            renderHourlyTable(rows, `Tabulka cen — ${fmtDate(data.day)}`);
            return;
        }

        if (TAB === 'today') {
            const rows = normalizeRows(data.today || [], GRAN);
            renderStats(data.today_stats, '· dnes');
            const titlePart = GRAN === '15min' ? '15min ceny' : 'Hodinové ceny';
            renderHourlyChart(rows, `${titlePart} — dnes (${fmtDate(data.today_date)})`);
            renderHourlyTable(rows, `Tabulka cen — dnes`);
        }
        else if (TAB === 'tomorrow') {
            const rawRows = data.tomorrow || [];
            if (rawRows.length === 0) {
                document.getElementById('stats-cards').innerHTML =
                    '<div class="empty-msg" style="grid-column:1/-1;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:1.5rem">' +
                    '⏳ Ceny pro zítřek (' + fmtDate(data.tomorrow_date) + ') ještě nebyly publikovány.<br>' +
                    '<span style="font-size:0.85rem">OTE typicky publikuje denní fixing kolem 14:00.</span></div>';
                document.getElementById('chart-title').textContent = '';
                renderHourlyTable([], `Tabulka cen — zítra`);
                return;
            }
            const rows = normalizeRows(rawRows, GRAN);
            renderStats(data.tomorrow_stats, '· zítra');
            const titlePart = GRAN === '15min' ? '15min ceny' : 'Hodinové ceny';
            renderHourlyChart(rows, `${titlePart} — zítra (${fmtDate(data.tomorrow_date)})`);
            renderHourlyTable(rows, `Tabulka cen — zítra`);
        }
        else if (TAB === 'history') {
            const days = data.days || [];
            const label = days.length > 0
                ? `(${fmtDate(days[0].delivery_day)} → ${fmtDate(days[days.length-1].delivery_day)})`
                : '';
            renderStats(data.stats, '· za období');
            renderDailyChart(days, `Vývoj cen ${label}`);
            renderDailyTable(days, `Denní průměry`);
        }
    } catch (e) {
        console.error(e);
        document.getElementById('stats-cards').innerHTML =
            '<div class="empty-msg" style="grid-column:1/-1;color:var(--bad)">Chyba načítání: ' + e.message + '</div>';
    }
}

loadData();
</script>

</body>
</html>
