<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Billing\Payment> $items */
/** @var array<string,string> $filters */
$this->layout('layouts/admin');
$qs = http_build_query($filters);
?>
<?php $this->start('actions'); ?>
    <a class="btn btn-ghost btn-sm" href="/admin/payments/webhooks">Журнал вебхуков</a>
    <a class="btn btn-ghost btn-sm" href="/admin/payments/export<?= $qs ? '?' . $this->e($qs) : '' ?>">Экспорт CSV</a>
    <?php if ($gatewayReady): ?><form method="post" action="/admin/payments/reconcile" data-confirm="Сверить платежи за 14 дней с ЮKassa?"><?= $this->csrfField() ?><button class="btn btn-primary btn-sm" type="submit">Сверка с ЮKassa</button></form><?php endif; ?>
<?php $this->end(); ?>
<?php if (!$gatewayReady): ?><div class="note-box">ЮKassa не настроена: укажите shopId и секретный ключ в <a href="/admin/settings/yookassa">настройках</a>.</div><?php endif; ?>
<div class="toolbar">
    <form method="get" action="/admin/payments">
        <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Email, № или ID ЮKassa">
        <select name="status" aria-label="Статус"><option value="">Все статусы</option>
            <?php foreach (\App\Billing\PaymentStatus::cases() as $s): ?><option value="<?= $s->value ?>"<?= $this->selected($filters['status'] ?? '', $s->value) ?>><?= $this->e($s->label()) ?></option><?php endforeach; ?>
            <option value="refunded"<?= $this->selected($filters['status'] ?? '', 'refunded') ?>>С возвратами</option></select>
        <select name="kind" aria-label="Тип"><option value="">Все типы</option><?php foreach (\App\Billing\PaymentKind::cases() as $k): ?><option value="<?= $k->value ?>"<?= $this->selected($filters['kind'] ?? '', $k->value) ?>><?= $this->e($k->label()) ?></option><?php endforeach; ?></select>
        <input type="date" name="from" value="<?= $this->e($filters['from'] ?? '') ?>" aria-label="С даты"> — <input type="date" name="to" value="<?= $this->e($filters['to'] ?? '') ?>" aria-label="По дату">
        <button class="btn btn-ghost btn-sm" type="submit">Найти</button>
        <?php if ($filters): ?><a class="btn btn-ghost btn-sm" href="/admin/payments">Сбросить</a><?php endif; ?>
    </form>
</div>
<div class="summary-row"><span>Найдено: <strong><?= (int) $total ?></strong></span><span>Оплачено (за вычетом возвратов): <strong><?= $this->e($this->money($sum, true)) ?></strong></span></div>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>№</th><th>Дата</th><th>Покупатель</th><th>Описание</th><th class="num">Сумма</th><th>Статус</th><th>ЮKassa</th></tr></thead>
    <tbody>
    <?php foreach ($items as $p): ?>
        <tr>
            <td><a href="/admin/payments/<?= (int) $p->id ?>">#<?= (int) $p->id ?></a></td>
            <td class="nowrap"><?= $this->e($this->short($p->paidAt ?? $p->createdAt)) ?></td>
            <td><?php if ($p->userId): ?><a href="/admin/users/<?= (int) $p->userId ?>"><?= $this->e((string) $p->userName) ?></a><span class="sub"><?= $this->e((string) $p->userEmail) ?></span><?php else: ?><span class="muted">аккаунт удалён</span><?php endif; ?></td>
            <td><?= $this->e($p->description) ?><span class="sub"><?= $this->e($p->kind->label()) ?></span></td>
            <td class="num"><?= $this->e($this->money($p->amount, true)) ?><?= $p->refundedAmount > 0 ? '<span class="sub">возврат ' . $this->e($this->money($p->refundedAmount, true)) . '</span>' : '' ?></td>
            <td><span class="badge badge-<?= $this->e($p->displayTone()) ?>"><?= $this->e($p->displayStatus()) ?></span></td>
            <td class="muted small"><?= $this->e((string) $p->yookassaId) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="7" class="muted">Платежей нет.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/payments', 'query' => $filters]) ?>
