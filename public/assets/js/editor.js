/* Редактор статей: WYSIWYG на contenteditable, выбор файлов из медиатеки, галерея, «Читайте также», подсказки тегов. */
(function () {
    'use strict';

    var form = document.getElementById('article-form');
    if (!form) { return; }

    var area = document.getElementById('rte-area');
    var source = document.getElementById('rte-source');
    var field = document.getElementById('body-field');
    var rte = form.querySelector('[data-rte]');
    var editable = rte && rte.dataset.editable === '1';
    var sourceMode = false;
    var savedRange = null;

    /* ---------- Очистка вставляемого HTML ---------- */
    var ALLOWED = { P: 1, BR: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, H2: 1, H3: 1, H4: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, A: 1, FIGURE: 1, FIGCAPTION: 1, IMG: 1, HR: 1, TABLE: 1, TBODY: 1, THEAD: 1, TR: 1, TD: 1, TH: 1 };
    var DROP = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, META: 1, LINK: 1, HEAD: 1, TITLE: 1 };

    function cleanNode(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
            if (child.nodeType === 8) { child.remove(); return; }
            if (child.nodeType !== 1) { return; }
            if (DROP[child.tagName]) { child.remove(); return; }
            cleanNode(child);
            if (!ALLOWED[child.tagName]) {
                while (child.firstChild) { node.insertBefore(child.firstChild, child); }
                child.remove();
                return;
            }
            Array.prototype.slice.call(child.attributes).forEach(function (attr) {
                var keep = (child.tagName === 'A' && attr.name === 'href' && /^(https?:|mailto:|\/|#)/i.test(attr.value)) ||
                    (child.tagName === 'IMG' && (attr.name === 'src' || attr.name === 'alt') && !/^javascript:/i.test(attr.value));
                if (!keep) { child.removeAttribute(attr.name); }
            });
        });
    }
    function cleanHtml(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        cleanNode(doc.body);
        return doc.body.innerHTML;
    }
    function escapeHtml(text) {
        return text.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }

    function rememberSelection() {
        var sel = window.getSelection();
        if (sel.rangeCount && area.contains(sel.anchorNode)) { savedRange = sel.getRangeAt(0).cloneRange(); }
    }
    function restoreSelection() {
        area.focus();
        if (savedRange) {
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(savedRange);
        }
    }
    function insertHtml(html) {
        restoreSelection();
        document.execCommand('insertHTML', false, html);
    }

    /* ---------- Редактор ---------- */
    if (area && editable) {
        area.addEventListener('keyup', rememberSelection);
        area.addEventListener('mouseup', rememberSelection);
        area.addEventListener('blur', rememberSelection);

        area.addEventListener('paste', function (event) {
            var clip = event.clipboardData;
            if (!clip) { return; }
            event.preventDefault();
            var html = clip.getData('text/html');
            if (html) {
                document.execCommand('insertHTML', false, cleanHtml(html));
            } else {
                var text = clip.getData('text/plain');
                var paragraphs = text.split(/\n{2,}/).map(function (p) { return '<p>' + escapeHtml(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
                document.execCommand('insertHTML', false, paragraphs);
            }
        });

        var toolbar = rte.querySelector('.rte-toolbar');
        toolbar.addEventListener('mousedown', function (event) { if (event.target.closest('button')) { event.preventDefault(); } });
        toolbar.addEventListener('click', function (event) {
            var button = event.target.closest('button');
            if (!button) { return; }
            if (button.hasAttribute('data-cmd')) {
                area.focus();
                document.execCommand(button.dataset.cmd, false, null);
            } else if (button.hasAttribute('data-block')) {
                area.focus();
                document.execCommand('formatBlock', false, button.dataset.block);
            } else if (button.hasAttribute('data-link')) {
                rememberSelection();
                var url = window.prompt('Адрес ссылки (https://…):', 'https://');
                if (url && /^(https?:\/\/|mailto:|\/)/i.test(url)) {
                    restoreSelection();
                    if (window.getSelection().isCollapsed) {
                        document.execCommand('insertHTML', false, '<a href="' + escapeHtml(url) + '">' + escapeHtml(url) + '</a>');
                    } else {
                        document.execCommand('createLink', false, url);
                    }
                }
            } else if (button.hasAttribute('data-image')) {
                rememberSelection();
                MediaPicker.open(function (item) {
                    var alt = escapeHtml(item.alt || item.name || '');
                    insertHtml('<figure><img src="' + item.full + '" srcset="' + item.srcset + '" sizes="(min-width: 800px) 740px, 100vw" alt="' + alt + '" width="' + item.width + '" height="' + item.height + '"><figcaption>' + alt + '</figcaption></figure><p><br></p>');
                });
            } else if (button.hasAttribute('data-video')) {
                rememberSelection();
                var embed = toEmbed(window.prompt('Ссылка на видео (YouTube, Rutube, VK Видео, Vimeo):', ''));
                if (embed) {
                    insertHtml('<p><iframe src="' + embed + '" width="640" height="360" allowfullscreen></iframe></p><p><br></p>');
                } else if (embed === false) {
                    News.toast('Не удалось распознать ссылку на видео', 'error');
                }
            } else if (button.hasAttribute('data-source')) {
                sourceMode = !sourceMode;
                if (sourceMode) {
                    source.value = area.innerHTML;
                    source.hidden = false;
                    area.hidden = true;
                } else {
                    area.innerHTML = source.value;
                    source.hidden = true;
                    area.hidden = false;
                }
                button.classList.toggle('is-on', sourceMode);
            }
        });
    }

    function toEmbed(url) {
        if (!url) { return null; }
        var m;
        if ((m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([\w-]{6,})/))) { return 'https://www.youtube-nocookie.com/embed/' + m[1]; }
        if ((m = url.match(/vimeo\.com\/(\d+)/))) { return 'https://player.vimeo.com/video/' + m[1]; }
        if ((m = url.match(/rutube\.ru\/(?:video|play\/embed)\/([\w]+)/))) { return 'https://rutube.ru/play/embed/' + m[1]; }
        if ((m = url.match(/vk\.com\/video(-?\d+)_(\d+)/))) { return 'https://vk.com/video_ext.php?oid=' + m[1] + '&id=' + m[2]; }
        return false;
    }

    form.addEventListener('submit', function () {
        field.value = sourceMode ? source.value : area.innerHTML;
    });

    /* ---------- Диалог медиатеки ---------- */
    var MediaPicker = (function () {
        var dialog = document.getElementById('media-dialog');
        var results = document.getElementById('media-results');
        var search = document.getElementById('media-search');
        var upload = document.getElementById('media-upload');
        var more = document.getElementById('media-more');
        var page = 1;
        var callback = null;

        function card(item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'media-item is-selectable';
            var img = document.createElement('img');
            img.src = item.thumb;
            img.alt = item.alt || '';
            img.loading = 'lazy';
            var meta = document.createElement('span');
            meta.className = 'meta';
            meta.textContent = item.name;
            button.appendChild(img);
            button.appendChild(meta);
            button.addEventListener('click', function () {
                dialog.close();
                if (callback) { callback(item); }
            });
            return button;
        }
        function load(reset) {
            if (reset) { page = 1; results.innerHTML = ''; }
            News.fetchJson('/admin/api/media?page=' + page + '&q=' + encodeURIComponent(search.value)).then(function (data) {
                data.items.forEach(function (item) { results.appendChild(card(item)); });
                more.hidden = !data.has_more;
                if (!data.items.length && page === 1) {
                    results.innerHTML = '<p class="muted">Файлов не найдено. Загрузите изображение.</p>';
                }
            }).catch(function (e) { News.toast(e.message, 'error'); });
        }
        search.addEventListener('input', News.debounce(function () { load(true); }, 250));
        more.addEventListener('click', function () { page++; load(false); });
        dialog.addEventListener('click', function (event) { if (event.target.closest('[data-close]')) { dialog.close(); } });
        upload.addEventListener('change', function () {
            if (!upload.files.length) { return; }
            var body = new FormData();
            Array.prototype.forEach.call(upload.files, function (file) { body.append('files[]', file); });
            fetch('/admin/media', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': News.csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.message || 'Ошибка загрузки'); } return d; }); })
                .then(function (data) {
                    upload.value = '';
                    (data.errors || []).forEach(function (m) { News.toast(m, 'error'); });
                    if (data.items && data.items.length) { News.toast('Загружено файлов: ' + data.items.length); }
                    load(true);
                })
                .catch(function (e) { News.toast(e.message, 'error'); });
        });
        return {
            open: function (cb) {
                callback = cb;
                search.value = '';
                load(true);
                dialog.showModal();
            }
        };
    })();

    /* ---------- Обложка ---------- */
    var coverChoose = document.getElementById('cover-choose');
    var coverClear = document.getElementById('cover-clear');
    var coverId = document.getElementById('cover-id');
    var coverPreview = document.getElementById('cover-preview');
    var blank = coverPreview ? coverPreview.getAttribute('src') : '';
    if (coverChoose) {
        coverChoose.addEventListener('click', function () {
            MediaPicker.open(function (item) {
                coverId.value = item.id;
                coverPreview.src = item.thumb;
                coverClear.hidden = false;
            });
        });
        coverClear.addEventListener('click', function () {
            coverId.value = '';
            coverPreview.src = blank.indexOf('data:') === 0 ? blank : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
            coverClear.hidden = true;
        });
    }

    /* ---------- Галерея фоторепортажа ---------- */
    var galleryList = document.getElementById('gallery-list');
    var galleryAdd = document.getElementById('gallery-add');
    function reindexGallery() {
        Array.prototype.forEach.call(galleryList.querySelectorAll('[data-row]'), function (row, i) {
            row.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/gallery\[\d+\]/, 'gallery[' + i + ']');
            });
        });
    }
    if (galleryList) {
        galleryAdd.addEventListener('click', function () {
            MediaPicker.open(function (item) {
                var i = galleryList.querySelectorAll('[data-row]').length;
                var row = document.createElement('div');
                row.className = 'gallery-row';
                row.setAttribute('data-row', '');
                row.innerHTML = '<img alt=""><div><input type="hidden"><input type="text" placeholder="Подпись к фото" maxlength="500"></div>' +
                    '<div class="row-actions"><button type="button" class="btn btn-ghost btn-xs" data-up>↑</button><button type="button" class="btn btn-ghost btn-xs" data-down>↓</button><button type="button" class="btn btn-ghost btn-xs" data-remove>✕</button></div>';
                row.querySelector('img').src = item.thumb;
                var inputs = row.querySelectorAll('input');
                inputs[0].name = 'gallery[' + i + '][media_id]';
                inputs[0].value = item.id;
                inputs[1].name = 'gallery[' + i + '][caption]';
                galleryList.appendChild(row);
            });
        });
        galleryList.addEventListener('click', function (event) {
            var row = event.target.closest('[data-row]');
            if (!row) { return; }
            if (event.target.closest('[data-remove]')) { row.remove(); }
            else if (event.target.closest('[data-up]') && row.previousElementSibling) { row.parentNode.insertBefore(row, row.previousElementSibling); }
            else if (event.target.closest('[data-down]') && row.nextElementSibling) { row.parentNode.insertBefore(row.nextElementSibling, row); }
            reindexGallery();
        });
    }

    /* ---------- Тип материала: показать галерею / признак эфира ---------- */
    var typeSelect = document.getElementById('type');
    if (typeSelect) {
        var syncType = function () {
            var panel = document.getElementById('gallery-panel');
            var live = document.getElementById('is-live-box');
            if (panel) { panel.hidden = typeSelect.value !== 'photo'; }
            if (live) { live.hidden = typeSelect.value !== 'liveblog'; }
        };
        typeSelect.addEventListener('change', syncType);
        syncType();
    }

    /* ---------- «Читайте также» ---------- */
    var relatedSearch = document.getElementById('related-search');
    if (relatedSearch) {
        var relatedList = document.getElementById('related-list');
        var relatedResults = document.getElementById('related-results');
        relatedList.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-remove-chip]');
            if (remove) { remove.closest('.chip-item').remove(); }
        });
        relatedSearch.addEventListener('input', News.debounce(function () {
            var q = relatedSearch.value.trim();
            if (q.length < 2) { relatedResults.hidden = true; return; }
            News.fetchJson('/admin/api/articles/lookup?q=' + encodeURIComponent(q) + '&except=' + relatedSearch.dataset.except).then(function (data) {
                relatedResults.innerHTML = '';
                data.items.forEach(function (item) {
                    var li = document.createElement('li');
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = item.title;
                    button.addEventListener('click', function () {
                        if (relatedList.querySelectorAll('.chip-item').length >= 8) { News.toast('Не более 8 материалов', 'error'); return; }
                        if (relatedList.querySelector('input[value="' + item.id + '"]')) { return; }
                        var chip = document.createElement('span');
                        chip.className = 'chip-item';
                        var label = document.createElement('span');
                        label.textContent = item.title.length > 38 ? item.title.slice(0, 37) + '…' : item.title;
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden'; hidden.name = 'related_ids[]'; hidden.value = item.id;
                        var x = document.createElement('button');
                        x.type = 'button'; x.setAttribute('data-remove-chip', ''); x.setAttribute('aria-label', 'Убрать'); x.textContent = '×';
                        chip.appendChild(label); chip.appendChild(hidden); chip.appendChild(x);
                        relatedList.appendChild(chip);
                        relatedResults.hidden = true;
                        relatedSearch.value = '';
                    });
                    li.appendChild(button);
                    relatedResults.appendChild(li);
                });
                relatedResults.hidden = !data.items.length;
            }).catch(function () {});
        }, 250));
    }

    /* ---------- Подсказки тегов ---------- */
    var tags = document.getElementById('tags');
    var tagOptions = document.getElementById('tag-options');
    if (tags && tagOptions) {
        tags.addEventListener('input', News.debounce(function () {
            var value = tags.value;
            var cut = value.lastIndexOf(',');
            var prefix = cut === -1 ? '' : value.slice(0, cut + 1) + ' ';
            var token = value.slice(cut + 1).trim();
            if (token.length < 1) { return; }
            News.fetchJson('/admin/api/tags?q=' + encodeURIComponent(token)).then(function (data) {
                tagOptions.innerHTML = '';
                data.items.forEach(function (name) {
                    var option = document.createElement('option');
                    option.value = prefix + name;
                    tagOptions.appendChild(option);
                });
            }).catch(function () {});
        }, 200));
    }
})();
