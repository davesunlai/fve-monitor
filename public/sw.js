// FVE Monitor Service Worker
const CACHE_NAME = 'fve-monitor-' + Date.now();
const CORE_ASSETS = [
    '/',
    '/index.php',
    '/assets/style.css',
    '/assets/app.js',
    '/manifest.json',
    '/assets/icon-192.png',
    '/assets/icon-512.png',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
];

// Při instalaci: nacache core soubory
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(CORE_ASSETS).catch(err => {
                console.warn('SW: některé core assets se nenacachovaly', err);
            });
        })
    );
});

// Při aktivaci: smaž staré cache
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
            );
        })
    );
    self.clients.claim();
});

// Fetch strategie
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Admin stránky (login, logout, admin UI) - NIKDY z cache, vždy live
    if (url.pathname.startsWith('/admin/')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // API volání: network-first, cache fallback
    if (url.pathname.includes('/api.php')) {
        event.respondWith(
            fetch(event.request)
                .then(resp => {
                    const clone = resp.clone();
                    caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
                    return resp;
                })
                .catch(() => caches.match(event.request))
        );
        return;
    }

    // Statické soubory: cache-first, síť fallback
    event.respondWith(
        caches.match(event.request).then(cached => {
            return cached || fetch(event.request).then(resp => {
                if (event.request.method === 'GET' && resp.status === 200) {
                    const clone = resp.clone();
                    caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
                }
                return resp;
            });
        })
    );
});

// Push notifikace
self.addEventListener('push', event => {
    let data = { title: 'FVE Monitor', body: 'Nová událost' };
    try {
        data = event.data ? event.data.json() : data;
    } catch (e) {}

    const options = {
        body: data.body,
        icon: data.icon || '/assets/icon-192.png',
        badge: '/assets/icon-192.png',
        data: { url: data.url || '/' },
        vibrate: [100, 50, 100],
        tag: data.tag || 'fve-alert',
    };

    event.waitUntil(self.registration.showNotification(data.title, options));
});

// Po kliku na notifikaci: otevři správnou URL + pošli message do appky
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = event.notification.data?.url || '/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(wins => {
            for (const w of wins) {
                if (w.url.includes('fve.sunlai.org') && 'focus' in w) {
                    w.focus();
                    w.postMessage({ type: 'navigate', url: url });
                    return;
                }
            }
            return clients.openWindow(url);
        })
    );
});

// SKIP_WAITING zpráva od klienta (při kliku na "Aktualizovat")
self.addEventListener('message', event => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
// Deploy: 1776837791
// Deploy: 1776837804
// Mobile test: 08:06:53
// Deploy hamburger: 1776838650
// Deploy passkey profile: 1776841581
// Deploy passkey test: 1776858234
// Test po Cache-Control opravě: 1776860721
// Test po Cache-Control opravě: 1776860841
// Deploy meta-fix: 1776860977
// Deploy passkey fix: 1776861374
// Deploy passkey fix: 1776861558
// Deploy passkey login button: 1776862082
// Deploy login button fix: 1776862171
// Deploy login button fix: 1776862226
// Deploy login button awk: 1776867800
// Deploy passkey validator args: 1776867996
// Deploy passkey attestation manager: 1776868043
// Deploy admin no-cache: 1776868214
// Deploy alarm details modal: 1776931834
// Deploy alarm modal fix: 1776931963
// Deploy iSolarCloud link: 1776932867
// Deploy linkify use: 1776933021
// Deploy linkify dash: 1776933075
// Deploy linkify history: 1776933157
// Deploy OTE metadata: 1777016607
// Deploy OTE id rozliseni: 1777164644
// Deploy OTE address fields: 1777165436
// Deploy OTE report: 1777165705
// Deploy CSV import: 1777167705
// Deploy code regex fix: 1777168002
// Deploy CSV import v2 strategy: 1777172282
// Deploy year tabs: 1777173910
// Deploy year persist: 1777174938
// Deploy yearly table fix: 1777175514
// Deploy yearly table fix v2: 1777175644
// Deploy custom toast helper: 1777256661
// Deploy versioned toast: 1777257221
// Deploy v4.8: 1777257292
// Deploy version test: 1777257692
// Deploy: Mon Apr 27 04:45:25 AM CEST 2026
// Deploy: Mon Apr 27 04:46:22 AM CEST 2026
// Deploy update message preload: 1777258481
// Deploy v4.9: 1777258735
// v5.0: Mon Apr 27 05:08:12 AM CEST 2026
// Deploy version footer: 1777259644
// v0.28: Mon Apr 27 05:16:09 AM CEST 2026
// v0.28: Mon Apr 27 05:17:04 AM CEST 2026
// v0.28: Mon Apr 27 05:17:24 AM CEST 2026
// v0.28: Mon Apr 27 05:17:25 AM CEST 2026
