/*
 * X2KEY service worker (ADR-037): makes the site installable, shows an offline page when the network is down,
 * and displays web push notifications. It never caches pages or API answers (prices, stock and orders must be
 * live); only the offline page and its logo are kept.
 */
const CACHE = 'x2key-offline-v1';
const OFFLINE = ['/offline.html', '/brand/x2key-logo-email.png', '/brand/icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    // Full page loads only: if the network fails, show the offline page. Everything else goes straight to the network.
    if (req.mode === 'navigate' && req.method === 'GET') {
        event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    }
});

self.addEventListener('push', (event) => {
    let data = { title: 'X2KEY', body: '', url: '/announcements' };
    try {
        data = { ...data, ...(event.data ? event.data.json() : {}) };
    } catch (e) {
        /* plain text payloads are ignored */
    }
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/brand/icon-192.png',
            badge: '/brand/icon-192.png',
            data: { url: data.url },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const w of wins) {
                if (w.url === url && 'focus' in w) return w.focus();
            }
            return self.clients.openWindow(url);
        }),
    );
});
