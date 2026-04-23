/**
 * FVE Monitor — frontend (tabulkový dashboard)
 */

const API = 'api.php';
const REFRESH_MS = 60_000;

let chartRealtime = null;
let chartYearly = null;
let detailMap = null;
let detailMarker = null;
let chart48h = null;
let plantsCache = [];
let sparklineCache = {};
let activePlantId = null;

document.addEventListener('DOMContentLoaded', () => {
    loadSummary();
    loadAlerts();
    setInterval(loadSummary, REFRESH_MS);
    setInterval(loadAlerts, REFRESH_MS);
    document.getElementById('detail-close').addEventListener('click', closeDetail);
});

async function loadSummary() {
    try {
        const [data, sparkData] = await Promise.all([
            fetchJson(`${API}?action=summary`),
            fetchJson(`${API}?action=sparkline`),
        ]);
        sparklineCache = sparkData.plants || {};
        renderPlants(data.plants);
        renderSummaryBar(data.plants);
        document.getElementById('last-update').textContent =
            'Aktualizováno: ' + new Date().toLocaleTimeString('cs-CZ');

        // Pokud je otevřený detail, refresh ho taky
        if (activePlantId !== null) {
            const p = plantsCache.find(x => x.id === activePlantId);
            if (p) refreshDetail(activePlantId);
        }
    } catch (e) {
        console.error('Summary chyba:', e);
        document.getElementById('plants-tbody').innerHTML =
            `<tr><td colspan="10" class="loading">Chyba: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function renderSummaryBar(plants) {
    let totalKw = 0, totalKwp = 0, totalToday = 0;
    let online = 0, offline = 0, alarms = 0;
    const now = Date.now();

    plants.forEach(p => {
        totalKw   += p.current_kw  || 0;
        totalKwp  += p.peak_power_kwp || 0;
        totalToday += p.today_kwh  || 0;
        alarms    += p.alarm_count || 0;

        const lastUpd = p.last_update ? new Date(p.last_update.replace(' ', 'T')).getTime() : 0;
        const stale = !lastUpd || (now - lastUpd) > 60 * 60 * 1000;
        if (stale) offline++;
        else online++;
    });

    const pct = totalKwp > 0 ? Math.round((totalKw / totalKwp) * 100) : 0;

    document.getElementById('sum-current-kw').textContent   = totalKw.toFixed(1);
    document.getElementById('sum-peak-kwp').textContent     = totalKwp.toFixed(0);
    document.getElementById('sum-current-pct').textContent  = pct;
    document.getElementById('sum-today-energy').innerHTML   = formatEnergy(totalToday);
    document.getElementById('sum-plants-count').textContent = plants.length;
    document.getElementById('sum-online').textContent       = online;
    document.getElementById('sum-offline').textContent      = offline;
    document.getElementById('sum-alarms').textContent       = alarms;

    document.getElementById('offline-pill').classList.toggle('hidden', offline === 0);
    document.getElementById('alarm-pill').classList.toggle('hidden', alarms === 0);
}

function renderPlants(plants) {
    plantsCache = plants;
    const tbody = document.getElementById('plants-tbody');
    if (!plants.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="loading">Žádné aktivní elektrárny.</td></tr>';
        return;
    }

    const now = Date.now();

    tbody.innerHTML = plants.map(p => {
        const m = p.month;
        const ratioPct = Math.round((m.ratio || 0) * 100);
        const pctOfPeak = p.peak_power_kwp > 0
            ? Math.round((p.current_kw / p.peak_power_kwp) * 100) : 0;

        const lastUpd = p.last_update ? new Date(p.last_update.replace(' ', 'T')).getTime() : 0;
        const ageMin = lastUpd ? Math.round((now - lastUpd) / 60000) : null;
        const offline = !lastUpd || ageMin > 60;
        const fault   = p.fault_status === 1 || p.fault_status === 2;
        const alarms  = p.alarm_count || 0;
        const lowPower = m.status === 'underperform';

        // Rowclass podle priority alertů
        let rowClass = 'row-ok';
        if (fault)            rowClass = 'row-fault';
        else if (alarms > 0)  rowClass = 'row-alarm';
        else if (offline)     rowClass = 'row-offline';
        else if (lowPower)    rowClass = 'row-warn';

        const lastUpdStr = lastUpd
            ? (ageMin < 1 ? 'právě teď'
               : ageMin < 60 ? `${ageMin} min`
               : `${Math.round(ageMin/60)} h`)
            : 'bez dat';

        const ratioClass = ratioPct >= 95 ? 'value-good'
                         : ratioPct >= 70 ? 'value-warn' : 'value-bad';

        return `
        <tr class="${rowClass}" onclick="openDetail(${p.id}, '${escapeAttr(p.name)}')">
            <td class="col-status"><span class="dot dot-${rowClass}"></span></td>
            <td class="col-name">
                <div class="name-main">${escapeHtml(p.name)}</div>
                <div class="name-meta">${p.peak_power_kwp.toFixed(1)} kWp · <code>${escapeHtml(p.code)}</code></div>
            </td>
            <td class="col-num">${p.current_kw.toFixed(1)}<span class="u">kW</span></td>
            <td class="col-num">${pctOfPeak} %</td>
            <td class="col-num">${p.today_kwh.toFixed(1)}<span class="u">kWh</span></td>
            <td class="col-num">${formatEnergy(m.actual_kwh)}</td>
            <td class="col-num">${formatEnergy(m.expected_kwh)}</td>
            <td class="col-num ${ratioClass}">${ratioPct} %</td>
            <td class="col-num ${offline ? 'value-offline' : ''}">${lastUpdStr}</td>
            <td class="col-sparkline"><canvas class="sparkline" data-plant-id="${p.id}" width="120" height="32"></canvas></td>
            <td class="col-num ${alarms > 0 ? 'value-bad' : ''}">${alarms}</td>
        </tr>`;
    }).join('');

    // Vykresli sparkliny do všech canvasů
    document.querySelectorAll('canvas.sparkline').forEach(canvas => {
        const plantId = parseInt(canvas.dataset.plantId, 10);
        const points = sparklineCache[plantId] || [];
        drawSparkline(canvas, points);
    });
}

function drawSparkline(canvas, points) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    if (points.length < 2) {
        ctx.fillStyle = '#6b7684';
        ctx.font = '10px system-ui';
        ctx.textAlign = 'center';
        ctx.fillText('málo dat', w/2, h/2 + 3);
        return;
    }

    // Osy: x = pozice v poli 0..n-1, y = % nominálu 0..100
    const maxP = Math.max(100, ...points.map(pt => pt.p));
    const xStep = w / (points.length - 1);

    // Plocha pod křivkou
    ctx.beginPath();
    ctx.moveTo(0, h);
    points.forEach((pt, i) => {
        const x = i * xStep;
        const y = h - (pt.p / maxP) * h;
        ctx.lineTo(x, y);
    });
    ctx.lineTo(w, h);
    ctx.closePath();
    ctx.fillStyle = 'rgba(245, 184, 0, 0.2)';
    ctx.fill();

    // Křivka
    ctx.beginPath();
    points.forEach((pt, i) => {
        const x = i * xStep;
        const y = h - (pt.p / maxP) * h;
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.strokeStyle = '#f5b800';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // Linka pro 50 % nominálu (jako reference)
    ctx.beginPath();
    const y50 = h - (50 / maxP) * h;
    ctx.moveTo(0, y50);
    ctx.lineTo(w, y50);
    ctx.strokeStyle = 'rgba(139, 148, 158, 0.3)';
    ctx.lineWidth = 1;
    ctx.setLineDash([2, 3]);
    ctx.stroke();
    ctx.setLineDash([]);
}

// ───────────── Detail ─────────────
async function openDetail(plantId, name) {
    activePlantId = plantId;
    document.getElementById('detail-name').textContent = name;
    document.getElementById('plant-detail').classList.remove('hidden');
    await refreshDetail(plantId);
    document.getElementById('plant-detail').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function refreshDetail(plantId) {
    try {
        const [realtime, yearly, range48] = await Promise.all([
            fetchJson(`${API}?action=realtime&plant=${plantId}`),
            fetchJson(`${API}?action=yearly&plant=${plantId}&y=${new Date().getFullYear()}`),
            fetchJson(`${API}?action=range&plant=${plantId}&hours=48`),
        ]);
        renderRealtimeChart(realtime.samples);
        render48hChart(range48.samples);
        renderYearlyChart(yearly);
        renderYearlyTable(yearly);
        renderDetailMap(plantId);
    } catch (e) {
        console.error('Detail chyba:', e);
    }
}

function closeDetail() {
    activePlantId = null;
    document.getElementById('plant-detail').classList.add('hidden');
    if (chartRealtime) { chartRealtime.destroy(); chartRealtime = null; }
    if (chart48h)      { chart48h.destroy();      chart48h = null; }
    if (chartYearly)   { chartYearly.destroy();   chartYearly = null; }
    if (detailMap)     { detailMap.remove();      detailMap = null; detailMarker = null; }
}

function renderRealtimeChart(samples) {
    const ctx = document.getElementById('chart-realtime').getContext('2d');
    if (chartRealtime) chartRealtime.destroy();
    const labels = samples.map(s => s.ts.substring(11, 16));
    const data   = samples.map(s => parseFloat(s.power_kw));
    chartRealtime = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Výkon (kW)',
                data,
                borderColor: '#f5b800',
                backgroundColor: 'rgba(245, 184, 0, 0.15)',
                fill: true,
                tension: 0.3,
                pointRadius: 0,
                borderWidth: 2,
            }],
        },
        options: chartOptions('kW'),
    });
}

function render48hChart(samples) {
    const ctx = document.getElementById('chart-48h').getContext('2d');
    if (chart48h) chart48h.destroy();

    // Labels: "Po 14:30", "Po 15:00", ...
    const days = ['Ne','Po','Út','St','Čt','Pá','So'];
    const labels = samples.map(s => {
        const d = new Date(s.ts.replace(' ', 'T'));
        return days[d.getDay()] + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
    });
    const data = samples.map(s => parseFloat(s.power_kw));

    chart48h = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Výkon (kW)',
                data,
                borderColor: '#f5b800',
                backgroundColor: 'rgba(245, 184, 0, 0.15)',
                fill: true,
                tension: 0.3,
                pointRadius: 0,
                borderWidth: 2,
            }],
        },
        options: {
            ...chartOptions('kW'),
            scales: {
                x: {
                    ticks: {
                        color: '#8b949e',
                        maxTicksLimit: 12,
                        autoSkip: true,
                    },
                    grid: { color: '#2d3743' }
                },
                y: { ticks: { color: '#8b949e' }, grid: { color: '#2d3743' }, beginAtZero: true },
            },
        },
    });
}

function renderYearlyChart(yearly) {
    const ctx = document.getElementById('chart-yearly').getContext('2d');
    if (chartYearly) chartYearly.destroy();
    const months = ['Led','Úno','Bře','Dub','Kvě','Čvn','Čvc','Srp','Zář','Říj','Lis','Pro'];
    const expected = [], actual = [];
    for (let i = 1; i <= 12; i++) {
        expected.push(yearly.monthly_expected[i] || 0);
        actual.push(yearly.monthly_actual[i] || 0);
    }
    chartYearly = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: months,
            datasets: [
                { label: 'PVGIS predikce', data: expected,
                  backgroundColor: 'rgba(139, 148, 158, 0.5)',
                  borderColor: 'rgba(139, 148, 158, 1)', borderWidth: 1 },
                { label: 'Skutečnost', data: actual,
                  backgroundColor: 'rgba(245, 184, 0, 0.7)',
                  borderColor: 'rgba(245, 184, 0, 1)', borderWidth: 1 },
            ],
        },
        options: chartOptions('kWh'),
    });
}

function renderYearlyTable(yearly) {
    const months = ['Leden','Únor','Březen','Duben','Květen','Červen',
                    'Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
    const currentMonth = new Date().getMonth() + 1;
    let totalExpected = 0, totalActual = 0;

    const rows = months.map((name, i) => {
        const m = i + 1;
        const exp = parseFloat(yearly.monthly_expected[m] || 0);
        const act = parseFloat(yearly.monthly_actual[m] || 0);
        totalExpected += exp;
        totalActual += act;
        const ratio = exp > 0 ? (act / exp) : 0;
        const ratioPct = Math.round(ratio * 100);
        const diff = act - exp;
        let rowClass = '', statusIcon = '—';
        if (m === currentMonth) { rowClass = 'row-current'; statusIcon = '▶'; }
        else if (m > currentMonth) { rowClass = 'row-future'; statusIcon = '·'; }
        else if (act === 0) { rowClass = 'row-nodata'; statusIcon = '·'; }
        else if (ratio >= 0.95) { rowClass = 'row-ok'; statusIcon = '✓'; }
        else if (ratio >= 0.70) { rowClass = 'row-warn'; statusIcon = '⚠'; }
        else { rowClass = 'row-bad'; statusIcon = '✕'; }
        const actualStr = (m > currentMonth || act === 0) ? '—' : formatEnergy(act);
        const diffStr = (m > currentMonth || act === 0) ? '' :
            (diff >= 0 ? '+' : '') + formatEnergy(diff);
        const ratioStr = (m > currentMonth || act === 0) ? '—' : ratioPct + ' %';
        return `
            <tr class="${rowClass}">
                <td class="col-icon">${statusIcon}</td>
                <td class="col-month">${name}</td>
                <td class="col-num">${formatEnergy(exp)}</td>
                <td class="col-num">${actualStr}</td>
                <td class="col-num col-diff">${diffStr}</td>
                <td class="col-num col-ratio">${ratioStr}</td>
            </tr>`;
    }).join('');
    const totalRatio = totalExpected > 0 ? Math.round((totalActual / totalExpected) * 100) : 0;
    const totalDiff = totalActual - totalExpected;
    const container = document.getElementById('yearly-table');
    container.innerHTML = `
        <table class="yearly-table">
            <thead><tr>
                <th></th><th>Měsíc</th>
                <th class="col-num">PVGIS</th>
                <th class="col-num">Skutečnost</th>
                <th class="col-num">Rozdíl</th>
                <th class="col-num">Plnění</th>
            </tr></thead>
            <tbody>${rows}</tbody>
            <tfoot><tr>
                <td></td>
                <td><strong>${yearly.year} celkem</strong></td>
                <td class="col-num"><strong>${formatEnergy(totalExpected)}</strong></td>
                <td class="col-num"><strong>${formatEnergy(totalActual)}</strong></td>
                <td class="col-num col-diff"><strong>${totalDiff >= 0 ? '+' : ''}${formatEnergy(totalDiff)}</strong></td>
                <td class="col-num col-ratio"><strong>${totalRatio} %</strong></td>
            </tr></tfoot>
        </table>`;
}

function renderDetailMap(plantId) {
    const plant = plantsCache.find(p => p.id === plantId);
    const mapDiv = document.getElementById('detail-map');
    if (!plant || !plant.latitude || !plant.longitude) {
        mapDiv.innerHTML = '<div style="padding:1rem;color:var(--text-dim);text-align:center">GPS souřadnice nejsou k dispozici</div>';
        return;
    }
    const lat = parseFloat(plant.latitude);
    const lng = parseFloat(plant.longitude);
    if (detailMap) {
        detailMap.setView([lat, lng], 18);
        if (detailMarker) detailMap.removeLayer(detailMarker);
        detailMarker = L.marker([lat, lng]).addTo(detailMap)
            .bindPopup('<strong>' + plant.name + '</strong><br>' + plant.peak_power_kwp + ' kWp');
        return;
    }
    detailMap = L.map('detail-map', { center: [lat, lng], zoom: 18, scrollWheelZoom: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap', maxZoom: 19,
    }).addTo(detailMap);
    detailMarker = L.marker([lat, lng]).addTo(detailMap)
        .bindPopup('<strong>' + plant.name + '</strong><br>' + plant.peak_power_kwp + ' kWp');
}

function chartOptions(yUnit) {
    return {
        responsive: true, maintainAspectRatio: true,
        plugins: {
            legend: { labels: { color: '#e6edf3' } },
            tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)} ${yUnit}` } },
        },
        scales: {
            x: { ticks: { color: '#8b949e' }, grid: { color: '#2d3743' } },
            y: { ticks: { color: '#8b949e' }, grid: { color: '#2d3743' }, beginAtZero: true },
        },
    };
}

// ───────────── Alerty ─────────────
async function loadAlerts() {
    try {
        const data = await fetchJson(`${API}?action=alerts`);
        renderAlerts(data.alerts);
        const badge = document.getElementById('alert-badge');
        if (data.count > 0) {
            badge.textContent = data.count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    } catch (e) {
        console.error('Alerts chyba:', e);
    }
}

function renderAlerts(alerts) {
    const list = document.getElementById('alerts-list');
    if (!alerts.length) {
        list.innerHTML = '<li class="empty">Žádná aktivní upozornění.</li>';
        return;
    }
    list.innerHTML = alerts.map(a => `
        <li class="severity-${a.severity}">
            <div class="alert-message">
                <strong>${escapeHtml(a.plant_name)}</strong> · ${linkifyUrls(a.message)}
                <div class="alert-meta">${formatRelative(a.created_at)}</div>
            </div>
            <button class="alert-ack" onclick="ackAlert(${a.id})">Potvrdit</button>
        </li>
    `).join('');
}

async function ackAlert(id) {
    const note = prompt('Potvrdit alert — komentář (nepovinný):\n\nNapiš proč/co jsi udělal (např. "kontaktoval jsem instalatéra", "vyřešeno restartem inv.", apod.)\n\nNech prázdné pro jen potvrzení.');

    // Pokud uživatel zrušil dialog (Cancel), neděláme nic
    if (note === null) return;

    try {
        const result = await fetchJson(`${API}?action=ack&id=${id}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ note: note }),
        });
        if (result.error) {
            alert('Chyba: ' + result.error);
            if (result.error.includes('přihlásit')) {
                if (confirm('Přesměrovat na login?')) {
                    location.href = '/admin/login.php?r=' + encodeURIComponent(location.pathname + location.search);
                }
            }
            return;
        }
        loadAlerts();
    } catch (e) {
        alert('Chyba: ' + e.message);
    }
}

// ───────────── Helpery ─────────────
async function fetchJson(url, opts = {}) {
    const res = await fetch(url, opts);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();
    if (json.error) throw new Error(json.error);
    return json;
}
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
}
function escapeAttr(s) { return escapeHtml(s).replace(/'/g, "\\'"); }
function formatRelative(iso) {
    const d = new Date(iso.replace(' ', 'T'));
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60)    return 'právě teď';
    if (diff < 3600)  return Math.round(diff / 60) + ' min';
    if (diff < 86400) return Math.round(diff / 3600) + ' h';
    return Math.round(diff / 86400) + ' dní';
}
function monthName(m) {
    const names = ['','leden','únor','březen','duben','květen','červen',
                   'červenec','srpen','září','říjen','listopad','prosinec'];
    return names[m] || '?';
}
function formatEnergy(kwh) {
    if (kwh === null || kwh === undefined) return '—';
    if (Math.abs(kwh) >= 1000) {
        return (kwh / 1000).toFixed(2) + '<span class="u">MWh</span>';
    }
    return kwh.toFixed(0) + '<span class="u">kWh</span>';
}
window.openDetail = openDetail;
window.ackAlert   = ackAlert;

// ───────────── Service Worker (PWA) ─────────────
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => {
                console.log('SW registrován:', reg.scope);

                // Každých 5 minut zkontroluj, jestli není nová verze SW
                setInterval(() => reg.update(), 5 * 60 * 1000);

                // Poslouchej, jestli se objeví nová verze
                reg.addEventListener('updatefound', () => {
                    const newSW = reg.installing;
                    if (!newSW) return;

                    newSW.addEventListener('statechange', () => {
                        if (newSW.state === 'installed' && navigator.serviceWorker.controller) {
                            // Nová verze je nainstalovaná a čeká
                            showUpdateToast(newSW);
                        }
                    });
                });
            })
            .catch(err => console.warn('SW registrace selhala:', err));

        // Reload když se nový SW aktivuje
        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (refreshing) return;
            refreshing = true;
            window.location.reload();
        });
    });
}

function showUpdateToast(newSW) {
    // Jednoduchý toast dole uprostřed obrazovky
    const toast = document.createElement('div');
    toast.id = 'update-toast';
    toast.innerHTML = `
        <span>✨ Nová verze FVE Monitoru je k dispozici.</span>
        <button id="update-apply">Aktualizovat</button>
        <button id="update-dismiss" style="background:transparent;border:1px solid currentColor;margin-left:8px">Později</button>
    `;
    toast.style.cssText = `
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        background: var(--accent, #f5b800); color: #000;
        padding: 12px 20px; border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        display: flex; align-items: center; gap: 12px;
        z-index: 9999; font-size: 0.9rem;
    `;
    toast.querySelector('#update-apply').style.cssText = `
        background: #000; color: var(--accent, #f5b800);
        border: none; padding: 6px 14px; border-radius: 4px;
        font-weight: 600; cursor: pointer;
    `;
    document.body.appendChild(toast);

    toast.querySelector('#update-apply').onclick = () => {
        newSW.postMessage({ type: 'SKIP_WAITING' });
        // reload přijde přes controllerchange event
    };
    toast.querySelector('#update-dismiss').onclick = () => toast.remove();
}

// ───────────── Push notifikace ─────────────
async function initPushUI() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.log('Push API nepodporováno v tomto prohlížeči');
        return;
    }

    const btn = document.getElementById('push-toggle');
    if (!btn) return;
    btn.style.display = 'inline-block';

    // Zjistit aktuální stav
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();

    if (sub) {
        btn.textContent = '🔕 Vypnout notifikace';
        btn.onclick = async () => {
            await sub.unsubscribe();
            btn.textContent = '🔔 Zapnout notifikace';
            alert('Notifikace vypnuty');
            location.reload();
        };
    } else {
        btn.textContent = '🔔 Zapnout notifikace';
        btn.onclick = async () => {
            try {
                // Získat VAPID public key
                const { public_key } = await fetchJson(`${API}?action=vapid_key`);

                // Požádat uživatele o povolení
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') {
                    alert('Notifikace zamítnuty prohlížečem');
                    return;
                }

                // Subscribe
                const newSub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(public_key),
                });

                // Odeslat subscription na server
                const subJson = newSub.toJSON();
                await fetchJson(`${API}?action=push_subscribe`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(subJson),
                });

                alert('✓ Notifikace zapnuty! Dostaneš upozornění při alarmech.');
                location.reload();
            } catch (e) {
                console.error('Push subscribe chyba:', e);
                alert('Chyba: ' + e.message);
            }
        };
    }
}

// Helper: VAPID public key (base64url) → Uint8Array
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Spusť po načtení service workeru
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        setTimeout(initPushUI, 500); // počkej aby se SW registroval
    });
}

// Po kliku na push notifikaci SW pošle postMessage se stránkou k otevření
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', event => {
        if (event.data?.type === 'navigate' && event.data.url) {
            const url = new URL(event.data.url, location.origin);
            const plantId = parseInt(url.searchParams.get('plant'), 10);
            if (plantId) {
                // Počkej chvilku než se data načtou a otevři detail
                setTimeout(() => {
                    const p = plantsCache.find(x => x.id === plantId);
                    if (p) openDetail(plantId, p.name);
                }, 500);
            }
        }
    });
}

// Po načtení stránky zkontroluj URL parametr ?plant=X (přímý odkaz z notifikace)
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        const params = new URLSearchParams(location.search);
        const plantId = parseInt(params.get('plant'), 10);
        if (plantId && plantsCache.length > 0) {
            const p = plantsCache.find(x => x.id === plantId);
            if (p) openDetail(plantId, p.name);
        }
    }, 1000);
});
// Deploy: 2026-04-22 08:01:15

// ───────────── Hamburger menu ─────────────
function initMenu() {
    const btn     = document.getElementById('menu-btn');
    const menu    = document.getElementById('main-menu');
    const overlay = document.getElementById('menu-overlay');
    if (!btn || !menu) return;

    function openMenu() {
        menu.classList.remove('hidden');
        overlay.classList.remove('hidden');
        // Animace v dalším frame
        requestAnimationFrame(() => {
            menu.classList.add('open');
            btn.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            menu.setAttribute('aria-hidden', 'false');
        });
    }

    function closeMenu() {
        menu.classList.remove('open');
        btn.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
        menu.setAttribute('aria-hidden', 'true');
        // Počkej na animaci, pak schovej
        setTimeout(() => {
            menu.classList.add('hidden');
            overlay.classList.add('hidden');
        }, 200);
    }

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (menu.classList.contains('open')) closeMenu();
        else openMenu();
    });

    overlay.addEventListener('click', closeMenu);

    // Zavři menu po kliku na kteroukoli menu-item (kromě push-toggle)
    menu.querySelectorAll('a.menu-item').forEach(item => {
        item.addEventListener('click', () => {
            setTimeout(closeMenu, 100); // krátké zpoždění aby klik prošel
        });
    });

    // ESC zavře menu
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && menu.classList.contains('open')) closeMenu();
    });
}

// Spusť po DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMenu);
} else {
    initMenu();
}

// Auto-linkify URL v textu (bezpečné - HTML escape + pak linkify)
function linkifyUrls(text) {
    const escaped = escapeHtml(text);
    return escaped.replace(
        /(https?:\/\/[^\s]+)|(isolarcloud\.eu\/[^\s]+)/g,
        m => {
            const url = m.startsWith('http') ? m : 'https://' + m;
            return `<a href="${url}" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:underline">🔗 detail</a>`;
        }
    );
}
