/* Общие функции: fetch с CSRF, тосты, тема, cookie-баннер, меню, автодополнение поиска. */
(function () {
    'use strict';

    var News = (window.News = window.News || {});

    News.csrf = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    };

    /** JSON-запрос к API сайта. Бросает Error с полем data при статусе не 2xx. */
    News.fetchJson = function (url, options) {
        options = options || {};
        var headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': News.csrf()
        };
        var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin', signal: options.signal };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return fetch(url, init).then(function (response) {
            if (response.status === 204) { return {}; }
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok) {
                    var error = new Error(data.message || (response.status === 419 ? 'Сессия истекла. Обновите страницу.' : 'Не удалось выполнить действие'));
                    error.status = response.status;
                    error.data = data;
                    throw error;
                }
                return data;
            });
        });
    };

    News.toast = function (message, type) {
        var region = document.getElementById('toast-region');
        if (!region) { return; }
        var el = document.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' toast-error' : '');
        el.textContent = message;
        region.appendChild(el);
        setTimeout(function () { el.remove(); }, type === 'error' ? 6000 : 3500);
    };

    News.setCookie = function (name, value, days) {
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(value) + '; Max-Age=' + days * 86400 + '; Path=/; SameSite=Lax' + secure;
    };

    News.debounce = function (fn, wait) {
        var timer;
        return function () {
            var args = arguments, self = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    };

    /* ---- Тема оформления ---- */
    var themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var root = document.documentElement;
            var current = root.dataset.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            var next = current === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            try { localStorage.setItem('theme', next); } catch (e) { /* приватный режим */ }
            News.setCookie('theme', next, 365);
        });
    }

    /* ---- Cookie-баннер ---- */
    var banner = document.getElementById('cookie-banner');
    if (banner) {
        banner.addEventListener('click', function (event) {
            var button = event.target.closest('[data-consent]');
            if (!button) { return; }
            News.setCookie('cookie_consent', button.dataset.consent, 365);
            banner.remove();
        });
    }

    /* ---- Flash-сообщения ---- */
    document.addEventListener('click', function (event) {
        var close = event.target.closest('.flash-close');
        if (close) { close.parentElement.remove(); }
        // закрыть меню аккаунта по клику снаружи
        document.querySelectorAll('.user-menu[open]').forEach(function (menu) {
            if (!menu.contains(event.target)) { menu.removeAttribute('open'); }
        });
    });
    document.querySelectorAll('.flash-success').forEach(function (el) {
        setTimeout(function () { el.remove(); }, 7000);
    });

    /* ---- Подтверждение опасных действий: <form data-confirm="Текст"> ---- */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.dataset && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });

    /* ---- Автодополнение поиска ---- */
    var form = document.querySelector('form.search');
    if (form) {
        var input = form.querySelector('input[type="search"]');
        var box = form.querySelector('.suggest');
        var items = [];
        var active = -1;
        var controller = null;

        var close = function () {
            box.hidden = true;
            box.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            items = [];
            active = -1;
        };
        var render = function (data) {
            box.innerHTML = '';
            var groups = [['Материалы', data.articles || []], ['Рубрики и темы', data.topics || []]];
            var index = 0;
            groups.forEach(function (group) {
                if (!group[1].length) { return; }
                var section = document.createElement('div');
                section.className = 'suggest-group';
                section.setAttribute('role', 'group');
                var label = document.createElement('div');
                label.className = 'suggest-label';
                label.textContent = group[0];
                section.appendChild(label);
                group[1].forEach(function (item) {
                    var link = document.createElement('a');
                    link.href = item.url;
                    link.id = 'suggest-' + index++;
                    link.setAttribute('role', 'option');
                    var title = document.createElement('span');
                    title.textContent = item.title;
                    link.appendChild(title);
                    if (item.category) {
                        var small = document.createElement('small');
                        small.textContent = item.category;
                        link.appendChild(small);
                    }
                    section.appendChild(link);
                });
                box.appendChild(section);
            });
            items = Array.prototype.slice.call(box.querySelectorAll('a'));
            if (!items.length) {
                var empty = document.createElement('div');
                empty.className = 'suggest-empty';
                empty.textContent = 'Ничего не найдено — нажмите Enter для полного поиска';
                box.appendChild(empty);
            }
            box.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
        };
        var highlight = function (next) {
            items.forEach(function (el) { el.removeAttribute('aria-selected'); });
            active = next;
            if (active >= 0 && items[active]) {
                items[active].setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', items[active].id);
            }
        };

        var search = News.debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { close(); return; }
            if (controller) { controller.abort(); }
            controller = new AbortController();
            News.fetchJson(form.dataset.suggestUrl + '?q=' + encodeURIComponent(q), { signal: controller.signal })
                .then(render)
                .catch(function (e) { if (e.name !== 'AbortError') { close(); } });
        }, 180);

        input.addEventListener('input', search);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { close(); return; }
            if (!items.length) { return; }
            if (event.key === 'ArrowDown') { event.preventDefault(); highlight((active + 1) % items.length); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); highlight((active - 1 + items.length) % items.length); }
            else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); window.location.href = items[active].href; }
        });
        document.addEventListener('click', function (event) { if (!form.contains(event.target)) { close(); } });
    }

    /* ---- Service worker для push ---- */
    News.registerWorker = function () {
        if (!('serviceWorker' in navigator)) { return Promise.reject(new Error('Браузер не поддерживает push')); }
        return navigator.serviceWorker.register('/sw.js', { scope: '/' });
    };
})();
