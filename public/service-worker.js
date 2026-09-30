const CACHE = 'gaugeiq-v2';

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll([
            './',
            './manifest.json',
            './css/app.css',
            './js/app.js'
        ]))
    );
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', event => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : 'GaugeIQ alert' };
    }

    const title = data.title || 'GaugeIQ';
    const options = {
        body: data.body || 'Atmospheric pressure has changed.',
        icon: './icons/icon-192.png',
        badge: './icons/icon-192.png',
        data: { url: data.url || './' },
        tag: 'gaugeiq-pressure',
        renotify: true
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const target = event.notification.data?.url || './';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            for (const client of windowClients) {
                if ('focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }

            return clients.openWindow ? clients.openWindow(target) : undefined;
        })
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    if (url.pathname.endsWith('/service-worker.js') || url.pathname.endsWith('/manifest.json')) {
        event.respondWith(fetch(event.request));
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});
