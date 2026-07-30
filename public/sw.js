// Minimal service worker — exists to satisfy PWA installability checks
// (Chrome/Android requires a registered service worker with a fetch handler
// before it will generate a standalone WebAPK on "Add to Home Screen").
//
// It deliberately does no caching: every request just passes straight
// through to the network. This app is session/auth-driven, so caching
// pages would risk serving stale PIN screens or stale balances.
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});

// Parent-console push alerts (chore approvals, redemption requests).
self.addEventListener('push', (event) => {
    if (!event.data) return;

    const payload = event.data.json();

    event.waitUntil(
        self.registration.showNotification(payload.title || 'LevelUp Chores', {
            body: payload.body,
            icon: payload.icon || '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            tag: payload.tag,
            renotify: payload.renotify,
            data: payload.data,
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/parent/approvals';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if (client.url.includes(url) && 'focus' in client) {
                    return client.focus();
                }
            }

            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
