/* Service worker: показ push-уведомлений и переход по клику. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Новое уведомление', body: event.data ? event.data.text() : '' }; }
    var title = data.title || 'Новости Vancodex';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: '/assets/img/icon-192.png',
        badge: '/assets/img/icon-192.png',
        tag: data.tag || undefined,
        data: { url: data.url || '/' }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
        for (var i = 0; i < clients.length; i++) {
            if (clients[i].url === url && 'focus' in clients[i]) { return clients[i].focus(); }
        }
        return self.clients.openWindow(url);
    }));
});
