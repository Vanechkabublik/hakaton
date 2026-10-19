/* Общее поведение админки: меню, выбор строк, подтверждения, копирование. */
(function () {
    'use strict';

    var toggle = document.getElementById('admin-menu-toggle');
    var sidebar = document.getElementById('admin-sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (sidebar.classList.contains('is-open') && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('is-open');
            }
        });
    }

    /* «Выбрать все» в таблицах с массовыми действиями */
    document.addEventListener('change', function (event) {
        var all = event.target.closest('[data-select-all]');
        if (all) {
            var scope = all.closest('form') || document;
            scope.querySelectorAll('.row-check').forEach(function (box) { box.checked = all.checked; });
        }
        if (event.target.matches('[data-autosubmit]')) { event.target.form.submit(); }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var submitter = event.submitter;
        if (submitter && submitter.hasAttribute('formaction')) { return; } // кнопка действия над одной строкой
        if (form.id === 'bulk-form' || form.hasAttribute('data-bulk')) {
            var picked = form.querySelectorAll('.row-check:checked').length;
            var action = form.querySelector('[name="bulk_action"]');
            if (!picked || (action && !action.value)) {
                event.preventDefault();
                News.toast('Выберите строки и действие', 'error');
                return;
            }
            if (action && action.value === 'delete' && !window.confirm('Удалить выбранные (' + picked + ')? Это нельзя отменить.')) {
                event.preventDefault();
            }
        }
    });

    /* Подтверждение по клику на кнопке: data-confirm-click="Текст" */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-confirm-click]');
        if (button && !window.confirm(button.getAttribute('data-confirm-click'))) {
            event.preventDefault();
            event.stopPropagation();
        }
        var copy = event.target.closest('[data-copy]');
        if (copy) {
            var text = copy.getAttribute('data-copy');
            var done = function () { News.toast('Скопировано'); };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); }
            else { window.prompt('Скопируйте:', text); }
        }
    }, true);
})();
