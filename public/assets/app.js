/**
 * FVE Monitor — frontend (tabulkový dashboard)
 */

const API = 'api.php';
const REFRESH_MS = 60_000;

// Načti aktuální verzi + update message ze serveru
function refreshVersionInfo() {
    return fetch('/version.json?t=' + Date.now(), { cache: 'no-store' })
        .then(r => r.ok ? r.json() : null)
        .then(d => {
            if (!d) return;
            if (d.message) window.UPDATE_MESSAGE = d.message;
            if (d.version) {
                window.APP_VERSION = d.version;
                renderVersionFooter(d.version);
            }
        })
        .catch(() => {});
}

function renderVersionFooter(version) {
    let footer = document.getElementById('app-version-footer');
    if (!footer) {
        footer = document.createElement('div');
        footer.id = 'app-version-footer';
        footer.style.cssText = `
            text-align: center;
            color: var(--text-dim);
            font-size: 0.75rem;
            padding: 1rem 0 1.5rem 0;
            opacity: 0.7;
        `;
        document.body.appendChild(footer);
    }
    footer.textContent = `FVE Monitor v${version}`;
}

refreshVersionInfo();
setInterval(refreshVersionInfo, 5 * 60 * 1000);


let chartRealtime = null;
let chartYearly = null;
let detailMap = null;
let detailMarker = null;
let chart48h = null;
let plantsCache = [];
let sparklineCache = {};
let weatherCache = {};

// WMO weather code → emoji + label
function weatherIcon(code) {
    if (code === 0) return {icon: '☀️', label: 'Jasno'};
    if (code <= 2) return {icon: '🌤️', label: 'Polojasno'};
    if (code === 3) return {icon: '☁️', label: 'Zataženo'};
    if (code <= 48) return {icon: '🌫️', label: 'Mlha'};
    if (code <= 57) return {icon: '🌦️', label: 'Mrholení'};
    if (code <= 67) return {icon: '🌧️', label: 'Déšť'};
    if (code <= 77) return {icon: '🌨️', label: 'Sníh'};
    if (code <= 82) return {icon: '🌧️', label: 'Přeháňky'};
    if (code <= 86) return {icon: '🌨️', label: 'Sněhové přeháňky'};
    if (code <= 99) return {icon: '⛈️', label: 'Bouřka'};
    return {icon: '❓', label: '—'};
}

function renderWeatherCell(plantId) {
    const days = weatherCache[plantId];
    if (!days || !days.length) return '<span class="weather-empty">—</span>';
    const labels = ['Dnes', 'Zítra', 'Pozítří'];
    return '<div class="weather-cell">' + days.slice(0, 3).map((d, i) => {
        const w = weatherIcon(d.weather_code);
        return `<div class="weather-day" title="${labels[i]} ${d.date}: ${w.label}, max ${d.tmax}°C, odhad ${d.est_kwh} kWh">
            <div class="weather-icon">${w.icon}</div>
            <div class="weather-temp">${d.tmax}°</div>
            <div class="weather-kwh">${d.est_kwh}</div>
        </div>`;
    }).join('') + '</div>';
}

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
        const [data, sparkData, weatherData] = await Promise.all([
            fetchJson(`${API}?action=summary`),
            fetchJson(`${API}?action=sparkline`),
            fetchJson(`${API}?action=weather_summary`).catch(() => ({plants: {}})),
        ]);
        sparklineCache = sparkData.plants || {};
        weatherCache = weatherData.plants || {};
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
            <td class="col-weather">${renderWeatherCell(p.id)}</td><td class="col-sparkline"><canvas class="sparkline" data-plant-id="${p.id}" width="120" height="32"></canvas></td>
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
        const currentYear = new Date().getFullYear();

        // Při změně FVE resetuj vybraný rok (jinak udrž z kliků uživatele)
        if (window._currentDetailPlantId !== plantId) {
            window._selectedYear = null;
        }
        window._currentDetailPlantId = plantId;

        const yearToLoad = window._selectedYear || currentYear;

        const [realtime, yearly, range48, weather] = await Promise.all([
            fetchJson(`${API}?action=realtime&plant=${plantId}`),
            fetchJson(`${API}?action=yearly&plant=${plantId}&y=${yearToLoad}`),
            fetchJson(`${API}?action=range&plant=${plantId}&hours=96`),
            fetchJson(`${API}?action=weather_prediction&plant=${plantId}`).catch(() => null),
        ]);
        renderRealtimeChart(realtime.samples);
        render48hChart(range48.samples, weather, true);
        renderYearlyChart(yearly);
        renderYearlyTable(yearly);
        renderYearTabs(yearly, yearToLoad);
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

function render48hChart(samples, weather = null, extendForecast = false) {
    const ctx = document.getElementById('chart-48h').getContext('2d');
    if (chart48h) chart48h.destroy();

    const days = ['Ne','Po','Út','St','Čt','Pá','So'];
    const now = new Date();

    // Sestavíme lookup ts → kW pro weather a pvgis
    const weatherMap = {}, pvgisMap = {};
    if (weather) {
        (weather.forecast || []).forEach(r => { weatherMap[r.ts.substring(0,16)] = parseFloat(r.power_kw); });
        (weather.pvgis_profile || []).forEach(r => { pvgisMap[r.ts.substring(0,16)] = parseFloat(r.power_kw); });
    }

    // Rozšíříme vzorky o budoucích 48h z weather forecast — interpolováno na 15min kroky
    let allSamples = [...samples];
    if (extendForecast && weather && weather.forecast) {
        const lastTs = samples.length ? new Date(samples[samples.length-1].ts.replace(' ','T')) : now;
        const future48h = new Date(now.getTime() + 48 * 3600 * 1000);

        // Sestavíme pole hodinových forecast hodnot seřazených podle času
        const fcSorted = (weather.forecast || [])
            .map(r => ({ t: new Date(r.ts.replace(' ','T')), kw: parseFloat(r.power_kw) }))
            .filter(r => r.t > lastTs && r.t <= future48h)
            .sort((a,b) => a.t - b.t);

        // Interpoluj na 15min kroky (lineární interpolace mezi hodinovými body)
        for (let i = 0; i < fcSorted.length - 1; i++) {
            const t0 = fcSorted[i].t, kw0 = fcSorted[i].kw;
            const t1 = fcSorted[i+1].t, kw1 = fcSorted[i+1].kw;
            // 4 kroky po 15 min = 1 hodina
            for (let q = 0; q < 4; q++) {
                const frac = q / 4;
                const tq = new Date(t0.getTime() + frac * (t1.getTime() - t0.getTime()));
                const kw = kw0 + frac * (kw1 - kw0);
                const pad = n => String(n).padStart(2,'0');
                const tsStr = `${tq.getFullYear()}-${pad(tq.getMonth()+1)}-${pad(tq.getDate())} ${pad(tq.getHours())}:${pad(tq.getMinutes())}:00`;
                allSamples.push({ ts: tsStr, power_kw: null, _forecast: true });
            }
        }
    }

    const labels = allSamples.map(s => {
        const d = new Date(s.ts.replace(' ', 'T'));
        return days[d.getDay()] + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
    });

    // Aktualizuj podtitul s rozsahem grafu
    const rangeEl = document.getElementById('chart-48h-range');
    if (rangeEl && allSamples.length) {
        const fmt = ts => {
            const d = new Date(ts.replace(' ','T'));
            return days[d.getDay()] + ' ' + d.getDate() + '.' + (d.getMonth()+1) + '.';
        };
        const from = fmt(allSamples[0].ts);
        const to   = fmt(allSamples[allSamples.length-1].ts);
        rangeEl.textContent = `(${from} — ${to}, 4 dny historie + 2 dny předpověď)`;
    }
    const data = allSamples.map(s => s._forecast ? null : parseFloat(s.power_kw));

    // Weather forecast: null pro minulé, hodnota pro budoucí (celý rozsah včetně extension)
    const weatherData = allSamples.map(s => {
        const hourKey = s.ts.substring(0,11) + s.ts.substring(11,13) + ':00';
        const t = new Date(s.ts.replace(' ','T'));
        if (t <= now) return null;
        return weatherMap[hourKey] ?? null;
    });

    // PVGIS profil: vždy (průměrný den), null pro noční
    const pvgisData = allSamples.map(s => {
        const hourKey = s.ts.substring(0,11) + s.ts.substring(11,13) + ':00';
        const v = pvgisMap[hourKey];
        return (v !== undefined && v > 0) ? v : null;
    });

    const datasets = [
        {
            label: 'Výkon (kW)',
            data,
            borderColor: '#f5b800',
            backgroundColor: 'rgba(245, 184, 0, 0.15)',
            fill: true,
            tension: 0.3,
            pointRadius: 0,
            borderWidth: 2,
            order: 1,
        },
        {
            label: 'PVGIS průměr',
            data: pvgisData,
            borderColor: 'rgba(139, 148, 158, 0.7)',
            backgroundColor: 'transparent',
            borderDash: [5, 4],
            fill: false,
            tension: 0.4,
            pointRadius: 0,
            borderWidth: 1.5,
            spanGaps: false,
            order: 3,
        },
    ];

    if (weather) {
        datasets.splice(1, 0, {
            label: 'Předpověď počasí',
            data: weatherData,
            borderColor: 'rgba(80, 160, 255, 0.85)',
            backgroundColor: 'rgba(80, 160, 255, 0.08)',
            borderDash: [6, 3],
            fill: true,
            tension: 0.4,
            pointRadius: 0,
            borderWidth: 2,
            spanGaps: false,
            order: 2,
        });
    }

    // Plugin: svislá čára "teď" + popisek
    const nowLinePlugin = {
        id: 'nowLine',
        afterDraw(chart) {
            const now = new Date();
            const labels = chart.data.labels;
            const days = ['Ne','Po','Út','St','Čt','Pá','So'];
            const nowLabel = days[now.getDay()] + ' '
                + String(now.getHours()).padStart(2,'0') + ':'
                + String(now.getMinutes() < 30 ? '00' : '30');
            // Najdi nejbližší index
            let idx = -1, minDiff = Infinity;
            labels.forEach((l, i) => {
                // Porovnáme jen hodinu:minutu
                const diff = Math.abs(labels.indexOf(l) - labels.indexOf(nowLabel));
                // Jiný přístup: porovnej čas přímo
                const parts = l.match(/(\d+):(\d+)/);
                if (!parts) return;
                // Projdeme allSamples přes chart metadata
            });
            // Lepší: projdeme labels a najdeme první budoucí
            const nowTs = now.getTime();
            idx = labels.findIndex((l, i) => {
                // label = "Pá 14:15" — zkusíme zpětně dohledat ts z dat
                // Jednodušší: hledáme přechod past→future v datech
                const d = chart.data.datasets[0].data;
                if (i === 0) return false;
                return d[i-1] !== null && (d[i] === null || i === labels.length - 1);
            });
            // Fallback: hledej label odpovídající aktuálnímu času
            if (idx < 0) {
                const nowStr = days[now.getDay()] + ' '
                    + String(now.getHours()).padStart(2,'0') + ':'
                    + String(Math.floor(now.getMinutes()/15)*15).padStart(2,'0');
                idx = labels.findIndex(l => l === nowStr);
            }
            if (idx < 0) return;

            const xScale = chart.scales.x;
            const yScale = chart.scales.y;
            const x = xScale.getPixelForValue(idx);
            const yTop = yScale.top;
            const yBottom = yScale.bottom;
            const {ctx} = chart;

            ctx.save();
            // Svislá čára
            ctx.beginPath();
            ctx.moveTo(x, yTop);
            ctx.lineTo(x, yBottom);
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
            ctx.lineWidth = 1.5;
            ctx.setLineDash([4, 3]);
            ctx.stroke();
            ctx.setLineDash([]);
            // Popisek "TEĎ"
            ctx.fillStyle = 'rgba(255,255,255,0.55)';
            ctx.font = '600 10px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('TEĎ', x, yTop - 4);
            ctx.restore();
        }
    };

    chart48h = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets,
        },
        plugins: [nowLinePlugin],
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
    const currentYear = new Date().getFullYear();
    const currentMonth = new Date().getMonth() + 1;
    const displayYear  = parseInt(yearly.year || currentYear, 10);

    for (let i = 1; i <= 12; i++) {
        expected.push(yearly.monthly_expected[i] || 0);
        actual.push(yearly.monthly_actual[i] || 0);
    }

    // Weather forecast: zobrazujeme jen pro aktuální rok, aktuální + budoucí měsíce
    // Hodnota = PVGIS scaled by weather (zatím jednoduše: PVGIS pro budoucí, null pro minulé)
    // Bude doplněno přes weather_monthly až bude endpoint; nyní PVGIS jako proxy
    const weatherForecast = [];
    if (displayYear === currentYear) {
        for (let i = 1; i <= 12; i++) {
            if (i < currentMonth) {
                weatherForecast.push(null);
            } else if (i === currentMonth) {
                // Aktuální měsíc: interpolujeme PVGIS
                weatherForecast.push(yearly.monthly_expected[i] || null);
            } else {
                // Budoucí měsíce: PVGIS predikce jako proxy
                weatherForecast.push(yearly.monthly_expected[i] || null);
            }
        }
    }

    const datasets = [
        { label: 'PVGIS predikce', data: expected,
          backgroundColor: 'rgba(139, 148, 158, 0.5)',
          borderColor: 'rgba(139, 148, 158, 1)', borderWidth: 1, order: 3 },
        { label: 'Skutečnost', data: actual,
          backgroundColor: 'rgba(245, 184, 0, 0.7)',
          borderColor: 'rgba(245, 184, 0, 1)', borderWidth: 1, order: 2 },
    ];

    if (displayYear === currentYear) {
        datasets.push({
            label: 'Výhled (PVGIS)',
            data: weatherForecast,
            type: 'line',
            borderColor: 'rgba(80, 160, 255, 0.8)',
            backgroundColor: 'transparent',
            borderDash: [6, 3],
            pointRadius: 3,
            pointBackgroundColor: 'rgba(80, 160, 255, 0.8)',
            fill: false,
            tension: 0.3,
            borderWidth: 2,
            spanGaps: false,
            order: 1,
        });
    }

    chartYearly = new Chart(ctx, {
        type: 'bar',
        data: { labels: months, datasets },
        options: chartOptions('kWh'),
    });
}

function renderYearlyTable(yearly) {
    const months = ['Leden','Únor','Březen','Duben','Květen','Červen',
                    'Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
    const now = new Date();
    const currentYear = now.getFullYear();
    const currentMonth = now.getMonth() + 1;
    const displayYear = parseInt(yearly.year || currentYear, 10);
    const isCurrentYear = displayYear === currentYear;
    const isPastYear = displayYear < currentYear;
    const isFutureYear = displayYear > currentYear;
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
        let isFuture = false, isCurrent = false;
        if (isCurrentYear) {
            isCurrent = (m === currentMonth);
            isFuture  = (m > currentMonth);
        } else if (isFutureYear) {
            isFuture = true;
        }
        if (isCurrent)     { rowClass = 'row-current'; statusIcon = '▶'; }
        else if (isFuture) { rowClass = 'row-future';  statusIcon = '·'; }
        else if (act === 0) { rowClass = 'row-nodata'; statusIcon = '·'; }
        else if (ratio >= 0.95) { rowClass = 'row-ok'; statusIcon = '✓'; }
        else if (ratio >= 0.70) { rowClass = 'row-warn'; statusIcon = '⚠'; }
        else { rowClass = 'row-bad'; statusIcon = '✕'; }
        const actualStr = (isFuture || act === 0) ? '—' : formatEnergy(act);
        const diffStr = (isFuture || act === 0) ? '' :
            (diff >= 0 ? '+' : '') + formatEnergy(diff);
        const ratioStr = (isFuture || act === 0) ? '—' : ratioPct + ' %';
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
    // Použij text z window.UPDATE_MESSAGE (načteno při startu) nebo fallback
    const message = window.UPDATE_MESSAGE || '✨ Nová verze FVE Monitoru je k dispozici.';

    // Jednoduchý toast dole uprostřed obrazovky
    const toast = document.createElement('div');
    toast.id = 'update-toast';
    toast.innerHTML = `
        <span>${message}</span>
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


// ───────── Roční přepínač (tabs) ─────────
function renderYearTabs(yearly, currentYear) {
    const container = document.getElementById('yearly-year-tabs');
    if (!container) return;

    const startYear = parseInt(yearly.start_year || (currentYear - 1), 10);
    const endYear   = new Date().getFullYear();
    const activeYear = window._selectedYear || currentYear;

    const years = [];
    for (let y = startYear; y <= endYear; y++) years.push(y);

    container.innerHTML = years.map(y =>
        `<button class="${y === activeYear ? 'active' : ''}" data-year="${y}">${y}</button>`
    ).join('');

    container.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', () => loadYearForChart(parseInt(btn.dataset.year, 10)));
    });
}

async function loadYearForChart(year) {
    const plantId = window._currentDetailPlantId;
    if (!plantId) return;

    window._selectedYear = year;

    try {
        const yearly = await fetchJson(`${API}?action=yearly&plant=${plantId}&y=${year}`);
        renderYearlyChart(yearly);
        renderYearlyTable(yearly);
        // Update active button
        document.querySelectorAll('#yearly-year-tabs button').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.year, 10) === year);
        });
    } catch (e) {
        console.error('Chyba načítání roku:', e);
    }
}


// ───────── Debug toast (pro ruční volání z konzole) ─────────
// Použití v konzoli: showCustomToast("Nové verze 4.8 - klikni na aktualizaci")
window.showCustomToast = function(text) {
    // Pokud už toast je, nahraď ho
    const old = document.getElementById('update-toast');
    if (old) old.remove();

    const toast = document.createElement('div');
    toast.id = 'update-toast';
    toast.innerHTML = `
        <span>${text}</span>
        <button id="update-apply">Aktualizovat</button>
        <button id="update-dismiss" style="background:transparent;border:1px solid currentColor;margin-left:8px">Později</button>
    `;
    toast.style.cssText = `
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        background: var(--accent, #f5b800); color: #000;
        padding: 12px 20px; border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        display: flex; align-items: center; gap: 12px;
        z-index: 9999; font-size: 0.9rem; max-width: 90vw;
    `;
    toast.querySelector('#update-apply').style.cssText = `
        background: #000; color: var(--accent, #f5b800);
        border: none; padding: 6px 14px; border-radius: 4px;
        font-weight: 600; cursor: pointer;
    `;
    document.body.appendChild(toast);

    toast.querySelector('#update-apply').onclick = () => {
        location.reload();
    };
    toast.querySelector('#update-dismiss').onclick = () => toast.remove();
};
