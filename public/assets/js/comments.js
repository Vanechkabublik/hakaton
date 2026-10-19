/* Комментарии: публикация, ответы по дереву, лайки, жалобы, правка в течение 5 минут. */
(function () {
    'use strict';

    var list = document.getElementById('comments-list');
    var form = document.getElementById('comment-form');
    if (!list) { return; }

    var counter = document.getElementById('comments-count');
    var emptyNote = document.getElementById('comments-empty');
    var viewer = Number(list.dataset.viewer || 0);

    function articleIdFromForm() {
        if (!form) { return null; }
        var m = form.action.match(/articles\/(\d+)\/comments/);
        return m ? m[1] : null;
    }

    function bump(delta) {
        if (counter) { counter.textContent = Math.max(0, Number(counter.textContent) + delta); }
        if (emptyNote) { emptyNote.hidden = true; }
    }

    function busy(button, state) {
        if (!button) { return; }
        button.disabled = state;
        button.classList.toggle('is-loading', state);
    }

    function insertComment(data, parentId) {
        var holder = document.createElement('ul');
        holder.innerHTML = data.html.trim();
        var node = holder.firstElementChild;
        if (!node) { return; }
        var target = list;
        if (data.parent_id) {
            var parent = document.getElementById('comment-' + data.parent_id);
            if (parent) {
                var children = parent.querySelector(':scope > .comment-children');
                if (!children) {
                    children = document.createElement('ul');
                    children.className = 'comment-children';
                    parent.appendChild(children);
                }
                target = children;
            }
        }
        target.appendChild(node);
        node.classList.add('flash-target');
        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        initEditTimers(node);
    }

    /* Новый комментарий */
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var textarea = form.elements.body;
            var button = form.querySelector('button[type="submit"]');
            busy(button, true);
            News.fetchJson(form.action, { method: 'POST', body: { body: textarea.value } })
                .then(function (data) {
                    insertComment(data);
                    textarea.value = '';
                    if (data.status === 'approved') { bump(1); } else { News.toast(data.message); }
                })
                .catch(function (e) { News.toast(e.message, 'error'); })
                .then(function () { busy(button, false); });
        });
    }

    /* Делегирование действий */
    var reportDialog = document.getElementById('report-dialog');
    var reportTarget = null;

    list.addEventListener('click', function (event) {
        var button = event.target.closest('[data-action]');
        if (!button) { return; }
        var item = button.closest('.comment');
        var id = item.dataset.id;
        var action = button.dataset.action;
        if (!viewer) { window.location.href = '/login'; return; }

        if (action === 'like') {
            News.fetchJson('/api/comments/' + id + '/like', { method: 'POST', body: {} })
                .then(function (data) {
                    button.classList.toggle('is-active', data.liked);
                    button.setAttribute('aria-pressed', data.liked ? 'true' : 'false');
                    button.querySelector('[data-likes]').textContent = data.count;
                })
                .catch(function (e) { News.toast(e.message, 'error'); });
        } else if (action === 'reply') {
            toggleReply(item, id);
        } else if (action === 'edit') {
            startEdit(item, id, button);
        } else if (action === 'report' && reportDialog) {
            reportTarget = id;
            reportDialog.showModal();
        }
    });

    function toggleReply(item, id) {
        var existing = item.querySelector(':scope > .comment-main + .reply-form');
        if (existing) { existing.remove(); return; }
        var wrapper = document.createElement('form');
        wrapper.className = 'reply-form';
        wrapper.innerHTML = '<textarea rows="3" maxlength="3000" required placeholder="Ваш ответ"></textarea>' +
            '<div class="form-row"><button type="button" class="btn btn-ghost btn-sm" data-cancel>Отмена</button><button type="submit" class="btn btn-primary btn-sm">Ответить</button></div>';
        item.querySelector('.comment-main').insertAdjacentElement('afterend', wrapper);
        var textarea = wrapper.querySelector('textarea');
        textarea.focus();
        wrapper.querySelector('[data-cancel]').addEventListener('click', function () { wrapper.remove(); });
        wrapper.addEventListener('submit', function (event) {
            event.preventDefault();
            var send = wrapper.querySelector('[type="submit"]');
            busy(send, true);
            News.fetchJson('/api/articles/' + articleIdFromForm() + '/comments', { method: 'POST', body: { body: textarea.value, parent_id: Number(id) } })
                .then(function (data) {
                    wrapper.remove();
                    insertComment(data);
                    if (data.status === 'approved') { bump(1); } else { News.toast(data.message); }
                })
                .catch(function (e) { News.toast(e.message, 'error'); })
                .then(function () { busy(send, false); });
        });
    }

    function startEdit(item, id, button) {
        var bodyEl = item.querySelector('[data-comment-body]');
        if (item.querySelector('.edit-form')) { return; }
        var raw = bodyEl.dataset.raw || bodyEl.textContent;
        var wrapper = document.createElement('form');
        wrapper.className = 'edit-form';
        var textarea = document.createElement('textarea');
        textarea.rows = 3;
        textarea.maxLength = 3000;
        textarea.required = true;
        textarea.value = raw;
        wrapper.appendChild(textarea);
        var row = document.createElement('div');
        row.className = 'form-row';
        row.innerHTML = '<button type="button" class="btn btn-ghost btn-sm" data-cancel>Отмена</button><button type="submit" class="btn btn-primary btn-sm">Сохранить</button>';
        wrapper.appendChild(row);
        bodyEl.hidden = true;
        bodyEl.insertAdjacentElement('afterend', wrapper);
        textarea.focus();
        row.querySelector('[data-cancel]').addEventListener('click', function () { wrapper.remove(); bodyEl.hidden = false; });
        wrapper.addEventListener('submit', function (event) {
            event.preventDefault();
            var save = row.querySelector('[type="submit"]');
            busy(save, true);
            News.fetchJson('/api/comments/' + id, { method: 'POST', body: { body: textarea.value } })
                .then(function (data) {
                    bodyEl.innerHTML = data.body_html;
                    bodyEl.dataset.raw = data.body;
                    bodyEl.hidden = false;
                    wrapper.remove();
                    News.toast('Комментарий обновлён');
                })
                .catch(function (e) { News.toast(e.message, 'error'); })
                .then(function () { busy(save, false); });
        });
    }

    /* Кнопка «Изменить» исчезает через 5 минут после публикации */
    function initEditTimers(root) {
        root.querySelectorAll('[data-action="edit"]').forEach(function (button) {
            var left = new Date(button.dataset.until).getTime() - Date.now();
            if (left <= 0) { button.remove(); return; }
            setTimeout(function () {
                button.remove();
                var editor = button.closest('.comment') && button.closest('.comment').querySelector('.edit-form');
                if (editor) { editor.remove(); var b = button.closest('.comment').querySelector('[data-comment-body]'); if (b) { b.hidden = false; } }
            }, left);
        });
    }
    initEditTimers(list);

    /* Жалобы */
    if (reportDialog) {
        reportDialog.addEventListener('click', function (event) {
            if (event.target.closest('[data-close]')) { reportDialog.close(); }
        });
        document.getElementById('report-form').addEventListener('submit', function (event) {
            event.preventDefault();
            var reason = document.getElementById('report-reason').value;
            var details = document.getElementById('report-details').value;
            News.fetchJson('/api/comments/' + reportTarget + '/report', { method: 'POST', body: { reason: reason, details: details } })
                .then(function (data) { News.toast(data.message); reportDialog.close(); })
                .catch(function (e) { News.toast(e.message, 'error'); });
        });
    }

    /* Якорь на комментарий (#comment-123) */
    if (location.hash.indexOf('#comment-') === 0) {
        var target = document.querySelector(location.hash);
        if (target) { target.classList.add('flash-target'); target.scrollIntoView({ block: 'center' }); }
    }
})();
