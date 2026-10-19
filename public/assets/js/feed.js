/* Лента: подгрузка при прокрутке (IntersectionObserver) и фильтры по рубрикам и темам без перезагрузки. */
(function () {
    'use strict';

    var feed = document.getElementById('feed');
    if (!feed) { return; }

    var more = document.getElementById('feed-more');
    var moreBtn = document.getElementById('feed-more-btn');
    var end = document.getElementById('feed-end');
    var empty = document.getElementById('feed-empty');
    var filters = document.getElementById('feed-filters');
    var hero = document.querySelector('.hero');
    var heading = document.getElementById('page-heading');

    var state = {
        category: feed.dataset.category || '',
        tags: (feed.dataset.tags || '').split(',').filter(Boolean),
        next: feed.dataset.next || '',
        exclude: feed.dataset.exclude || '',
        author: feed.dataset.author || ''
    };
    var loading = false;
    var filtered = function () { return state.category !== '' || state.tags.length > 0; };

    function params(cursor) {
        var p = new URLSearchParams();
        if (state.category) { p.set('category', state.category); }
        if (state.tags.length) { p.set('tags', state.tags.join(',')); }
        if (state.author) { p.set('author', state.author); }
        if (!filtered() && state.exclude) { p.set('exclude', state.exclude); }
        if (cursor) { p.set('cursor', cursor); }
        return p;
    }

    function setMore() {
        var hasNext = state.next !== '';
        if (more) { more.hidden = !hasNext; }
        if (end) { end.hidden = hasNext || feed.children.length === 0; }
        if (empty) { empty.hidden = feed.children.length > 0; }
    }

    function load(reset) {
        if (loading || (!reset && !state.next)) { return; }
        loading = true;
        feed.classList.add('is-loading');
        if (moreBtn) { moreBtn.classList.add('is-loading'); }
        News.fetchJson('/api/feed?' + params(reset ? '' : state.next).toString())
            .then(function (data) {
                if (reset) { feed.innerHTML = ''; }
                feed.insertAdjacentHTML('beforeend', data.html);
                state.next = data.next || '';
                setMore();
            })
            .catch(function (error) { News.toast(error.message, 'error'); })
            .then(function () {
                loading = false;
                feed.classList.remove('is-loading');
                if (moreBtn) { moreBtn.classList.remove('is-loading'); }
                observe();
            });
    }

    /* Бесконечная прокрутка */
    var observer = null;
    function observe() {
        if (!('IntersectionObserver' in window) || !more) { return; }
        if (observer) { observer.disconnect(); }
        if (!state.next) { return; }
        observer = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) { load(false); }
        }, { rootMargin: '700px 0px' });
        observer.observe(more);
    }
    if (moreBtn) {
        moreBtn.addEventListener('click', function (event) { event.preventDefault(); load(false); });
    }

    /* Фильтры */
    function syncChips() {
        if (!filters) { return; }
        filters.querySelectorAll('[data-category]').forEach(function (el) {
            el.classList.toggle('is-active', el.dataset.category === state.category);
        });
        filters.querySelectorAll('[data-tag]').forEach(function (el) {
            el.classList.toggle('is-active', state.tags.indexOf(el.dataset.tag) !== -1);
        });
    }

    function applyFilters(push) {
        syncChips();
        var query = new URLSearchParams();
        if (state.category) { query.set('category', state.category); }
        if (state.tags.length) { query.set('tags', state.tags.join(',')); }
        var url = '/' + (query.toString() ? '?' + query.toString() : '');
        if (push) { history.pushState({ category: state.category, tags: state.tags }, '', url); }
        if (hero) { hero.hidden = filtered(); }
        if (heading) { heading.hidden = true; }
        state.next = '';
        load(true);
    }

    if (filters && !feed.dataset.fixed) {
        filters.addEventListener('click', function (event) {
            var chip = event.target.closest('a');
            if (!chip || event.metaKey || event.ctrlKey || event.shiftKey) { return; }
            if (chip.hasAttribute('data-category')) {
                event.preventDefault();
                state.category = chip.dataset.category;
                applyFilters(true);
            } else if (chip.hasAttribute('data-tag')) {
                event.preventDefault();
                var index = state.tags.indexOf(chip.dataset.tag);
                if (index === -1) { state.tags.push(chip.dataset.tag); } else { state.tags.splice(index, 1); }
                applyFilters(true);
            }
        });
        window.addEventListener('popstate', function () {
            var q = new URLSearchParams(location.search);
            state.category = q.get('category') || '';
            state.tags = (q.get('tags') || '').split(',').filter(Boolean);
            applyFilters(false);
        });
    }

    setMore();
    observe();
})();
