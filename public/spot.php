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
        <span class="spot-source">Zdroj: OTE-CR denní trh · kurz ČNB</span>
    </div>

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
    gap: 4px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 1rem;
    align-items: center;
    flex-wrap: wrap;
}
.spot-tab {
    padding: 10px 18px;
    background: var(--surface-2);
    color: var(--text-dim);
    text-decoration: none;
    border-radius: 6px 6px 0 0;
    border: 1px solid var(--border);
    border-bottom: none;
    font-weight: 500;
    transition: all 0.15s;
    margin-bottom: -1px;
}
.spot-tab:hover {
    background: var(--surface);
    color: var(--text);
}
.spot-tab.active {
    background: var(--surface);
    color: var(--accent);
    border-bottom: 1px solid var(--surface);
    font-weight: 600;
}
.spot-source {
    margin-left: auto;
    color: var(--text-dim);
    font-size: 0.78rem;
    padding: 0 8px 8px;
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
</style>

<script>
const TAB = <?= json_encode($tab) ?>;
const FROM = <?= json_encode($_GET['from'] ?? null) ?>;
const TO = <?= json_encode($_GET['to'] ?? null) ?>;

// ─── Helpery ───
const fmt = (v, dec = 2) => {
    if (v === null || v === undefined) return '—';
    return Number(v).toLocaleString('cs-CZ', { minimumFractionDigits: dec, maximumFractionDigits: dec });
};
const fmtDate = (s) => {
    const d = new Date(s + 'T00:00:00');
    return d.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric' });
};
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
function renderStats(stats, label = '') {
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
    const labels = rows.map(r => String(r.hour).padStart(2, '0') + ':00');
    const eur = rows.map(r => parseFloat(r.price_eur_mwh));
    const czk = rows.map(r => parseFloat(r.price_czk_mwh));

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
                    if (v < 0) return 'rgba(74, 222, 128, 0.7)';
                    if (v > 3500) return 'rgba(248, 113, 113, 0.8)';
                    return 'rgba(251, 191, 36, 0.7)';
                }),
                borderColor: 'rgba(251, 191, 36, 1)',
                borderWidth: 1,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const e = eur[ctx.dataIndex];
                            const k = czk[ctx.dataIndex];
                            return [`${fmt(k, 0)} Kč/MWh`, `${fmt(k / 1000, 3)} Kč/kWh`, `${fmt(e)} €/MWh`];
                        }
                    }
                }
            },
            scales: {
                y: {
                    title: { display: true, text: 'Kč/MWh' },
                    grid: { color: 'rgba(255,255,255,0.05)' }
                },
                x: { grid: { display: false } }
            }
        }
    });
}

// ─── Render: hodinová tabulka ───
function renderHourlyTable(rows, title) {
    document.getElementById('table-title').textContent = title;
    document.getElementById('spot-thead').innerHTML = `
        <tr>
            <th>Hodina</th>
            <th>Kč/kWh</th>
            <th>Kč/MWh</th>
            <th>EUR/MWh</th>
        </tr>
    `;
    if (!rows || rows.length === 0) {
        document.getElementById('spot-tbody').innerHTML = '<tr><td colspan="4" class="empty-msg">Žádná data — možná ještě nebyla publikována</td></tr>';
        return;
    }
    const czk = rows.map(r => parseFloat(r.price_czk_mwh));
    const min = Math.min(...czk), max = Math.max(...czk);
    document.getElementById('spot-tbody').innerHTML = rows.map(r => {
        const e = parseFloat(r.price_eur_mwh);
        const k = parseFloat(r.price_czk_mwh);
        return `
            <tr class="${cellClass(k, min, max)}">
                <td>${String(r.hour).padStart(2, '0')}:00 – ${String((r.hour + 1) % 24).padStart(2, '0')}:00</td>
                <td><strong>${fmt(k / 1000, 3)}</strong></td>
                <td class="${priceColor(e)}">${fmt(k, 0)}</td>
                <td>${fmt(e)}</td>
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

// ─── Načtení dat dle tabu ───
async function loadData() {
    try {
        let url = '/api.php?action=spot_prices';
        if (TAB === 'history') {
            url += '&from=' + (FROM || new Date(Date.now() - 30*86400000).toISOString().slice(0,10))
                 + '&to=' + (TO || new Date().toISOString().slice(0,10));
        }

        const r = await fetch(url);
        const data = await r.json();

        if (TAB === 'today') {
            const rows = data.today || [];
            renderStats(data.today_stats, '· dnes');
            renderHourlyChart(rows, `Hodinové ceny — dnes (${fmtDate(data.today_date)})`);
            renderHourlyTable(rows, `Tabulka cen — dnes`);
        }
        else if (TAB === 'tomorrow') {
            const rows = data.tomorrow || [];
            if (rows.length === 0) {
                document.getElementById('stats-cards').innerHTML =
                    '<div class="empty-msg" style="grid-column:1/-1;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:1.5rem">' +
                    '⏳ Ceny pro zítřek (' + fmtDate(data.tomorrow_date) + ') ještě nebyly publikovány.<br>' +
                    '<span style="font-size:0.85rem">OTE typicky publikuje denní fixing kolem 14:00.</span></div>';
                document.getElementById('chart-title').textContent = '';
                renderHourlyTable([], `Tabulka cen — zítra`);
                return;
            }
            renderStats(data.tomorrow_stats, '· zítra');
            renderHourlyChart(rows, `Hodinové ceny — zítra (${fmtDate(data.tomorrow_date)})`);
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
