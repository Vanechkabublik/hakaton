/* Оформление подписки: переключатель периода, промокод, виджет ЮKassa, ожидание статуса платежа. */
(function () {
    'use strict';

    /* ---- Переключатель месяц / год на странице тарифов ---- */
    var switcher = document.getElementById('period-switch');
    if (switcher) {
        switcher.addEventListener('click', function (event) {
            var button = event.target.closest('[data-period]');
            if (!button) { return; }
            switcher.querySelectorAll('[data-period]').forEach(function (b) {
                var active = b === button;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll('[data-period-grid]').forEach(function (grid) {
                grid.hidden = grid.dataset.periodGrid !== button.dataset.period;
            });
        });
        // по умолчанию показан месяц; без JS видны оба блока
        document.querySelectorAll('[data-period-grid="year"]').forEach(function (g) { g.hidden = true; });
    }

    /* ---- Промокод на странице оформления ---- */
    var form = document.getElementById('checkout-form');
    var apply = document.getElementById('promo-apply');
    if (form && apply) {
        var input = document.getElementById('promo');
        var err = document.getElementById('promo-error');
        var ok = document.getElementById('promo-ok');
        var recalc = function () {
            apply.disabled = true;
            News.fetchJson(form.dataset.quoteUrl, { method: 'POST', body: { promo: input.value } })
                .then(function (q) {
                    err.hidden = !q.promo_error;
                    err.textContent = q.promo_error || '';
                    ok.hidden = !q.promo_label;
                    ok.textContent = q.promo_label ? 'Промокод применён (' + q.promo_label + ')' : '';
                    var dl = document.getElementById('sum-discount-label');
                    var dd = document.getElementById('sum-discount');
                    var hasDiscount = q.discount > 0;
                    dl.hidden = dd.hidden = !hasDiscount;
                    dd.textContent = '−' + q.discount_text;
                    document.getElementById('sum-total').textContent = q.total_text;
                })
                .catch(function (e) { News.toast(e.message, 'error'); })
                .then(function () { apply.disabled = false; });
        };
        apply.addEventListener('click', recalc);
        input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); recalc(); } });
        form.addEventListener('submit', function (event) {
            var agree = document.getElementById('agree');
            if (agree && !agree.checked) {
                event.preventDefault();
                News.toast('Подтвердите согласие с условиями', 'error');
                agree.focus();
            }
        });
    }

    /* ---- Виджет ЮKassa ---- */
    var widget = document.getElementById('payment-form');
    if (widget && widget.dataset.token) {
        if (typeof window.YooMoneyCheckoutWidget !== 'function') {
            widget.innerHTML = '<p class="notice notice-warning">Не удалось загрузить платёжный виджет. Обновите страницу или выберите оплату с переходом на страницу ЮKassa.</p>';
        } else {
            var checkout = new window.YooMoneyCheckoutWidget({
                confirmation_token: widget.dataset.token,
                return_url: widget.dataset.returnUrl,
                error_callback: function () {
                    widget.insertAdjacentHTML('beforebegin', '<p class="notice notice-warning">Ошибка платёжного виджета. Попробуйте ещё раз.</p>');
                }
            });
            checkout.render('payment-form');
        }
    }

    /* ---- Ожидание подтверждения платежа ---- */
    var state = document.getElementById('payment-state');
    if (state && state.dataset.final === '0') {
        var id = state.dataset.paymentId;
        var tries = 0;
        var timer = setInterval(function () {
            tries++;
            News.fetchJson('/api/payments/' + id + '/status').then(function (data) {
                if (data.final) {
                    clearInterval(timer);
                    window.location.reload();
                } else if (tries > 50) {
                    clearInterval(timer);
                    var hint = document.getElementById('payment-hint');
                    if (hint) { hint.textContent = 'Подтверждение задерживается. Если деньги списаны, подписка включится автоматически в течение нескольких минут — мы пришлём письмо.'; }
                }
            }).catch(function () { /* временный сбой сети — повторим */ });
        }, 2500);
    }
})();
