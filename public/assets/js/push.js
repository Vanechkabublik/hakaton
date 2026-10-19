/* Web Push: подписка текущего устройства на уведомления (VAPID). */
(function () {
    'use strict';

    var enable = document.getElementById('push-enable');
    var disable = document.getElementById('push-disable');
    var status = document.getElementById('push-status');
    if (!enable) { return; }

    function say(text) { if (status) { status.textContent = text; } }

    function urlBase64ToUint8Array(base64) {
        var padding = '='.repeat((4 - base64.length % 4) % 4);
        var raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        enable.disabled = true;
        say('Ваш браузер не поддерживает push-уведомления.');
        return;
    }

    function refresh() {
        return navigator.serviceWorker.getRegistration('/').then(function (reg) {
            return reg ? reg.pushManager.getSubscription() : null;
        }).then(function (sub) {
            enable.hidden = !!sub;
            disable.hidden = !sub;
            if (Notification.permission === 'denied') { say('Уведомления заблокированы в настройках браузера для этого сайта.'); }
            return sub;
        });
    }

    enable.addEventListener('click', function () {
        enable.disabled = true;
        Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') { throw new Error('Разрешение на уведомления не получено'); }
            return News.registerWorker();
        }).then(function () {
            return navigator.serviceWorker.ready;
        }).then(function (reg) {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(enable.dataset.key) });
        }).then(function (sub) {
            return News.fetchJson('/account/push/subscribe', { method: 'POST', body: sub.toJSON() });
        }).then(function () {
            News.toast('Push-уведомления включены');
            var devices = document.getElementById('push-devices');
            if (devices) { devices.textContent = String(Number(devices.textContent) + 1); }
            return refresh();
        }).catch(function (e) { News.toast(e.message, 'error'); })
          .then(function () { enable.disabled = false; });
    });

    disable.addEventListener('click', function () {
        navigator.serviceWorker.getRegistration('/').then(function (reg) {
            return reg ? reg.pushManager.getSubscription() : null;
        }).then(function (sub) {
            if (!sub) { return null; }
            var endpoint = sub.endpoint;
            return sub.unsubscribe().then(function () {
                return News.fetchJson('/account/push/unsubscribe', { method: 'POST', body: { endpoint: endpoint } });
            });
        }).then(function () {
            News.toast('Push-уведомления выключены');
            return refresh();
        }).catch(function (e) { News.toast(e.message, 'error'); });
    });

    refresh();
})();
