/* Графики дашборда на чистом SVG: колонки и линии, подсказки при наведении, табличный вид для доступности. */
(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var dataEl = document.getElementById('dash-data');
    if (!dataEl) { return; }
    var data = JSON.parse(dataEl.textContent);
    var ruNumber = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
    var ruCompact = new Intl.NumberFormat('ru-RU', { notation: 'compact', maximumFractionDigits: 1 });
    var monthShort = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

    function svg(name, attrs) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        return node;
    }

    function formatValue(value, format) {
        return format === 'money' ? ruNumber.format(value / 100) + ' ₽' : ruNumber.format(value);
    }
    function formatTick(value, format) {
        var v = format === 'money' ? value / 100 : value;
        return ruCompact.format(v);
    }
    function formatDate(iso, long) {
        var parts = iso.split('-');
        var day = Number(parts[2]);
        return day + ' ' + monthShort[Number(parts[1]) - 1] + (long ? ' ' + parts[0] : '');
    }
    /** Округление верхней границы оси до «красивого» числа. */
    function niceMax(max) {
        if (max <= 0) { return 4; }
        var exp = Math.pow(10, Math.floor(Math.log10(max)));
        var frac = max / exp;
        var nice = frac <= 1 ? 1 : frac <= 2 ? 2 : frac <= 2.5 ? 2.5 : frac <= 5 ? 5 : 10;
        return nice * exp;
    }

    function topRounded(x, y, w, h, r) {
        r = Math.min(r, w / 2, h);
        return 'M' + x + ',' + (y + h) + ' V' + (y + r) + ' Q' + x + ',' + y + ' ' + (x + r) + ',' + y +
            ' H' + (x + w - r) + ' Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) + ' V' + (y + h) + ' Z';
    }

    function render(container) {
        var series = data[container.dataset.chart] || [];
        var type = container.dataset.type;
        var format = container.dataset.format;
        var label = container.dataset.label;
        var width = Math.max(container.clientWidth, 260);
        var height = 220;
        var m = { l: 46, r: 14, t: 14, b: 26 };
        var plotW = width - m.l - m.r;
        var plotH = height - m.t - m.b;
        var n = series.length;
        var max = niceMax(Math.max.apply(null, series.map(function (p) { return p.value; }).concat([0])));
        var step = n > 1 ? plotW / (n - (type === 'bar' ? 0 : 1)) : plotW;

        container.innerHTML = '';
        var root = svg('svg', { viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': label + ' по дням' });
        var y = function (v) { return m.t + plotH - (v / max) * plotH; };
        var xBar = function (i) { return m.l + i * step + step / 2; };
        var xLine = function (i) { return m.l + (n > 1 ? i * step : plotW / 2); };
        var x = type === 'bar' ? xBar : xLine;

        // сетка и подписи оси Y (гайдлайн: тонкие сплошные линии, подписи — токенами текста)
        for (var t = 0; t <= 4; t++) {
            var value = (max / 4) * t;
            var yy = Math.round(y(value)) + 0.5;
            root.appendChild(svg('line', { class: 'grid-line', x1: m.l, x2: width - m.r, y1: yy, y2: yy }));
            var text = svg('text', { class: 'axis-text', x: m.l - 8, y: yy + 4, 'text-anchor': 'end' });
            text.textContent = formatTick(value, format);
            root.appendChild(text);
        }
        // подписи оси X: до 6 штук
        var every = Math.max(1, Math.ceil(n / 6));
        series.forEach(function (p, i) {
            if (i % every !== 0 && i !== n - 1) { return; }
            if (i === n - 1 && (n - 1) % every !== 0 && (n - 1) - (Math.floor((n - 1) / every) * every) < every / 2) { return; }
            var tx = svg('text', { class: 'axis-text', x: x(i), y: height - 6, 'text-anchor': i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle' });
            tx.textContent = formatDate(p.date);
            root.appendChild(tx);
        });

        var marks = [];
        if (type === 'bar') {
            var bw = Math.max(2, Math.min(24, step - 2));
            series.forEach(function (p, i) {
                if (p.value <= 0) { return; }
                var h = Math.max(2, plotH - (y(p.value) - m.t));
                var bar = svg('path', { class: 'bar', d: topRounded(xBar(i) - bw / 2, y(p.value), bw, h, 4) });
                root.appendChild(bar);
                marks[i] = bar;
            });
        } else {
            var points = series.map(function (p, i) { return [xLine(i), y(p.value)]; });
            if (points.length) {
                var d = points.map(function (pt, i) { return (i ? 'L' : 'M') + pt[0].toFixed(1) + ',' + pt[1].toFixed(1); }).join(' ');
                root.appendChild(svg('path', { class: 'area', d: d + ' L' + points[points.length - 1][0] + ',' + (m.t + plotH) + ' L' + points[0][0] + ',' + (m.t + plotH) + ' Z' }));
                root.appendChild(svg('path', { class: 'line', d: d }));
                var last = points[points.length - 1];
                root.appendChild(svg('circle', { class: 'dot', cx: last[0], cy: last[1], r: 4 }));
                // выборочная прямая подпись: только последнее значение
                var endLabel = svg('text', { class: 'value-label', x: Math.min(last[0], width - m.r), y: Math.max(12, last[1] - 10), 'text-anchor': 'end' });
                endLabel.textContent = formatValue(series[series.length - 1].value, format);
                root.appendChild(endLabel);
            }
        }

        // слой наведения: перекрестие для линий, подсветка колонки для столбцов
        var cross = svg('line', { class: 'crosshair', y1: m.t, y2: m.t + plotH, visibility: 'hidden' });
        var hoverDot = svg('circle', { class: 'dot', r: 4, visibility: 'hidden' });
        if (type === 'line') { root.appendChild(cross); root.appendChild(hoverDot); }
        var overlay = svg('rect', { x: m.l, y: m.t, width: plotW, height: plotH, fill: 'transparent' });
        root.appendChild(overlay);
        container.appendChild(root);

        var tip = document.createElement('div');
        tip.className = 'chart-tooltip';
        tip.hidden = true;
        container.appendChild(tip);

        var showAt = function (i) {
            if (i < 0 || i >= n) { return; }
            var p = series[i];
            marks.forEach(function (bar) { if (bar) { bar.classList.remove('is-hover'); } });
            if (marks[i]) { marks[i].classList.add('is-hover'); }
            if (type === 'line') {
                cross.setAttribute('x1', xLine(i)); cross.setAttribute('x2', xLine(i)); cross.setAttribute('visibility', 'visible');
                hoverDot.setAttribute('cx', xLine(i)); hoverDot.setAttribute('cy', y(p.value)); hoverDot.setAttribute('visibility', 'visible');
            }
            // textContent вместо innerHTML — подписи считаются недоверенными данными
            tip.textContent = '';
            var value = document.createElement('strong');
            value.textContent = formatValue(p.value, format);
            var row = document.createElement('span');
            var key = document.createElement('i');
            key.className = 'key';
            row.appendChild(key);
            row.appendChild(document.createTextNode(label + ' · ' + formatDate(p.date, true)));
            tip.appendChild(value);
            tip.appendChild(row);
            tip.hidden = false;
            var tipX = Math.min(Math.max(x(i), 70), width - 70);
            tip.style.left = tipX + 'px';
            tip.style.top = Math.max(y(p.value), 40) + 'px';
        };
        var hide = function () {
            tip.hidden = true;
            cross.setAttribute('visibility', 'hidden');
            hoverDot.setAttribute('visibility', 'hidden');
            marks.forEach(function (bar) { if (bar) { bar.classList.remove('is-hover'); } });
        };
        overlay.addEventListener('pointermove', function (event) {
            var rect = root.getBoundingClientRect();
            var px = (event.clientX - rect.left) * (width / rect.width);
            var index = type === 'bar' ? Math.floor((px - m.l) / step) : Math.round((px - m.l) / step);
            showAt(Math.max(0, Math.min(n - 1, index)));
        });
        overlay.addEventListener('pointerleave', hide);

        // итог за период в заголовке
        var totalEl = container.closest('.chart-card').querySelector('[data-total]');
        if (totalEl) {
            var sum = series.reduce(function (acc, p) { return acc + p.value; }, 0);
            totalEl.textContent = 'всего ' + formatValue(sum, format);
        }

        // табличный вид (повторяет значения без наведения)
        var holder = container.parentElement.querySelector('.chart-table');
        if (!holder) {
            holder = document.createElement('details');
            holder.className = 'chart-table';
            var summary = document.createElement('summary');
            summary.textContent = 'Показать таблицей';
            holder.appendChild(summary);
            var wrap = document.createElement('div');
            wrap.className = 'table-wrap';
            var table = document.createElement('table');
            table.className = 'table';
            var tbody = document.createElement('tbody');
            series.slice().reverse().forEach(function (p) {
                var tr = document.createElement('tr');
                var td1 = document.createElement('td'); td1.textContent = formatDate(p.date, true);
                var td2 = document.createElement('td'); td2.className = 'num'; td2.textContent = formatValue(p.value, format);
                tr.appendChild(td1); tr.appendChild(td2); tbody.appendChild(tr);
            });
            table.appendChild(tbody); wrap.appendChild(table); holder.appendChild(wrap);
            container.parentElement.appendChild(holder);
        }
    }

    var containers = Array.prototype.slice.call(document.querySelectorAll('[data-chart]'));
    containers.forEach(render);
    if ('ResizeObserver' in window) {
        var timer;
        var observer = new ResizeObserver(function () {
            clearTimeout(timer);
            timer = setTimeout(function () { containers.forEach(render); }, 120);
        });
        observer.observe(document.querySelector('.chart-grid') || document.body);
    }
})();
