/* Страница статьи: прогресс чтения, шеринг, закладки, реакции, подписка на автора, live-блог, галерея. */
(function () {
    'use strict';

    var article = document.getElementById('article');
    var articleId = article ? article.dataset.articleId : null;

    /* ---- Индикатор прогресса и маяк дочитывания ---- */
    var body = document.getElementById('article-body');
    var bar = document.getElementById('read-progress-bar');
    if (article && body && bar) {
        var maxProgress = 0;
        var started = Date.now();
        var ticking = false;
        var update = function () {
            ticking = false;
            var rect = body.getBoundingClientRect();
            var total = rect.height - window.innerHeight * 0.4;
            var done = Math.min(1, Math.max(0, (-rect.top + window.innerHeight * 0.4) / Math.max(total, 1)));
            if (article.dataset.locked === '1') { done = Math.min(done, 0.3); }
            bar.style.width = (done * 100).toFixed(1) + '%';
            maxProgress = Math.max(maxProgress, Math.round(done * 100));
        };
        var onScroll = function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } };
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        update();

        var sent = 0;
        var beacon = function () {
            if (article.dataset.track !== '1' || article.dataset.locked === '1' || maxProgress <= sent) { return; }
            sent = maxProgress;
            var payload = JSON.stringify({ article_id: Number(articleId), progress: maxProgress, seconds: Math.round((Date.now() - started) / 1000) });
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/api/track/progress', new Blob([payload], { type: 'application/json' }));
            } else {
                fetch('/api/track/progress', { method: 'POST', body: payload, headers: { 'Content-Type': 'application/json' }, keepalive: true });
            }
        };
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { beacon(); } });
        window.addEventListener('pagehide', beacon);
    }

    /* ---- Поделиться ---- */
    var share = document.getElementById('share');
    if (share) {
        var copy = document.getElementById('copy-link');
        var nativeBtn = document.getElementById('native-share');
        if (copy) {
            copy.addEventListener('click', function () {
                var url = share.dataset.url;
                var done = function () { News.toast('Ссылка скопирована'); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(done, function () { window.prompt('Скопируйте ссылку:', url); });
                } else {
                    window.prompt('Скопируйте ссылку:', url);
                }
            });
        }
        if (nativeBtn && navigator.share) {
            nativeBtn.hidden = false;
            nativeBtn.addEventListener('click', function () {
                navigator.share({ title: share.dataset.title, url: share.dataset.url }).catch(function () {});
            });
        }
    }

    /* ---- Закладка ---- */
    var bookmark = document.getElementById('bookmark-btn');
    if (bookmark) {
        bookmark.addEventListener('click', function () {
            bookmark.disabled = true;
            News.fetchJson('/api/articles/' + articleId + '/bookmark', { method: 'POST', body: {} })
                .then(function (data) {
                    bookmark.classList.toggle('is-active', data.bookmarked);
                    bookmark.setAttribute('aria-pressed', data.bookmarked ? 'true' : 'false');
                    bookmark.querySelector('[data-label]').textContent = data.bookmarked ? 'Сохранено' : 'Сохранить';
                    News.toast(data.bookmarked ? 'Статья сохранена в закладки' : 'Удалено из закладок');
                })
                .catch(function (e) { News.toast(e.message, 'error'); })
                .then(function () { bookmark.disabled = false; });
        });
    }

    /* ---- Реакции ---- */
    var reactions = document.getElementById('reactions');
    if (reactions) {
        reactions.addEventListener('click', function (event) {
            var button = event.target.closest('.reaction-btn');
            if (!button) { return; }
            if (button.dataset.guest) { window.location.href = '/login'; return; }
            News.fetchJson('/api/articles/' + articleId + '/react', { method: 'POST', body: { kind: button.dataset.kind } })
                .then(function (data) {
                    reactions.querySelectorAll('.reaction-btn').forEach(function (el) {
                        var active = data.mine === el.dataset.kind;
                        el.classList.toggle('is-active', active);
                        el.setAttribute('aria-pressed', active ? 'true' : 'false');
                        el.querySelector('[data-count]').textContent = data.counts[el.dataset.kind] || 0;
                    });
                })
                .catch(function (e) { News.toast(e.message, 'error'); });
        });
    }

    /* ---- Подписка на автора ---- */
    document.querySelectorAll('.follow-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            News.fetchJson('/api/authors/' + button.dataset.authorId + '/follow', { method: 'POST', body: {} })
                .then(function (data) {
                    button.classList.toggle('is-active', data.following);
                    button.setAttribute('aria-pressed', data.following ? 'true' : 'false');
                    button.textContent = data.following ? 'Вы подписаны' : 'Подписаться';
                    var counter = document.getElementById('followers-count');
                    if (counter) { counter.textContent = data.followers; }
                    if (data.following) { News.toast('Мы сообщим о новых материалах автора'); }
                })
                .catch(function (e) { News.toast(e.message, 'error'); })
                .then(function () { button.disabled = false; });
        });
    });

    /* ---- Live-блог: опрос новых записей ---- */
    if (article && article.dataset.live === '1') {
        var entries = document.getElementById('live-entries');
        var last = Number(article.dataset.liveLast || 0);
        setInterval(function () {
            if (document.visibilityState !== 'visible') { return; }
            News.fetchJson('/api/articles/' + articleId + '/live?after=' + last).then(function (data) {
                if (data.count > 0 && entries) {
                    var emptyNote = document.getElementById('live-empty');
                    if (emptyNote) { emptyNote.remove(); }
                    entries.insertAdjacentHTML('afterbegin', data.html);
                    last = Math.max(last, data.last_id);
                }
            }).catch(function () { /* сеть временно недоступна */ });
        }, 20000);
    }

    /* ---- Галерея фоторепортажа ---- */
    var lightbox = document.getElementById('lightbox');
    if (lightbox && typeof lightbox.showModal === 'function') {
        document.addEventListener('click', function (event) {
            var link = event.target.closest('[data-lightbox]');
            if (link) {
                event.preventDefault();
                lightbox.querySelector('img').src = link.href;
                lightbox.querySelector('.lightbox-caption').textContent = link.dataset.caption || '';
                lightbox.showModal();
            } else if (event.target === lightbox || event.target.closest('#lightbox [data-close]')) {
                lightbox.close();
            }
        });
    }
})();
