<?php
/** @var \App\Core\View\View $this */
/** @var \App\Billing\Payment $payment */
/** @var list<array<string,mixed>> $refunds */
/** @var list<array<string,mixed>> $events */
$this->layout('layouts/admin');
$refundable = $payment->refundableAmount();
?>
<?php $this->start('actions'); ?><a class="btn btn-ghost btn-sm" href="/admin/payments">← К списку</a><?php $this->end(); ?>
<div class="two-col">
    <section class="form-panel" style="max-width:none">
        <h2>Платёж #<?= (int) $payment->id ?> <span class="badge badge-<?= $this->e($payment->displayTone()) ?>"><?= $this->e($payment->displayStatus()) ?></span></h2>
        <table class="kv-table">
            <tr><th>Покупатель</th><td><?= $payment->userId ? '<a href="/admin/users/' . (int) $payment->userId . '">' . $this->e((string) $payment->userName) . '</a> (' . $this->e((string) $payment->userEmail) . ')' : '<span class="muted">аккаунт удалён</span>' ?></td></tr>
            <tr><th>Описание</th><td><?= $this->e($payment->description) ?> · <?= $this->e($payment->kind->label()) ?></td></tr>
            <tr><th>Сумма</th><td><strong><?= $this->e($this->money($payment->amount, true)) ?></strong><?= $payment->discountAmount > 0 ? ' (скидка ' . $this->e($this->money($payment->discountAmount, true)) . ' от ' . $this->e($this->money($payment->originalAmount, true)) . ')' : '' ?></td></tr>
            <tr><th>Возвращено</th><td><?= $this->e($this->money($payment->refundedAmount, true)) ?></td></tr>
            <tr><th>Способ оплаты</th><td><?= $this->e((string) ($payment->paymentMethodTitle ?? '—')) ?><?= $payment->isRecurring ? ' · рекуррентный' : '' ?><?= $payment->saveMethod ? ' · карта сохранена для автопродления' : '' ?></td></tr>
            <tr><th>Создан / оплачен</th><td><?= $this->e($this->dateTime($payment->createdAt)) ?> / <?= $this->e($this->dateTime($payment->paidAt)) ?></td></tr>
            <tr><th>ID в ЮKassa</th><td><code><?= $this->e((string) $payment->yookassaId) ?></code></td></tr>
            <?php if ($payment->cancelReason): ?><tr><th>Причина отмены</th><td><?= $this->e($payment->cancelReason) ?></td></tr><?php endif; ?>
            <?php if ($payment->subscriptionId): ?><tr><th>Подписка</th><td>#<?= (int) $payment->subscriptionId ?></td></tr><?php endif; ?>
        </table>
        <?php if ($gatewayReady && $payment->yookassaId): ?>
            <form method="post" action="/admin/payments/<?= (int) $payment->id ?>/sync"><?= $this->csrfField() ?><button class="btn btn-ghost btn-sm" type="submit">Обновить статус из ЮKassa</button></form>
        <?php endif; ?>
    </section>

    <section class="form-panel" style="max-width:none">
        <h2>Возврат</h2>
        <?php if ($refundable > 0 && $gatewayReady): ?>
            <form method="post" action="/admin/payments/<?= (int) $payment->id ?>/refund" data-confirm="Оформить возврат средств покупателю?" novalidate>
                <?= $this->csrfField() ?>
                <p class="muted">Доступно к возврату: <strong><?= $this->e($this->money($refundable, true)) ?></strong>. Полный возврат закрывает подписку, оплаченную этим платежом.</p>
                <div class="field"><label for="amount">Сумма, ₽ (пусто — вернуть всё)</label><input id="amount" type="text" name="amount" inputmode="decimal" placeholder="<?= $this->e(\App\Billing\Money::toInput($refundable)) ?>"><?= $this->error('amount') ?></div>
                <div class="field"><label for="reason">Причина</label><input id="reason" type="text" name="reason" maxlength="300" placeholder="Например: по просьбе клиента"></div>
                <button class="btn btn-primary" type="submit">Вернуть средства</button>
            </form>
        <?php else: ?><p class="muted"><?= $payment->isSucceeded() ? 'Нечего возвращать.' : 'Возврат возможен только для оплаченных платежей.' ?></p><?php endif; ?>
        <?php if ($refunds): ?>
            <h3 style="margin-top:1.25rem">История возвратов</h3>
            <table class="table"><tbody><?php foreach ($refunds as $r): ?><tr><td><?= $this->e($this->short(new \DateTimeImmutable((string) $r['created_at']))) ?></td><td class="num"><?= $this->e($this->money((int) $r['amount'], true)) ?></td><td><?= $this->e($r['status']) ?></td><td class="muted small"><?= $this->e((string) $r['reason']) ?> <?= $r['admin_name'] ? '· ' . $this->e($r['admin_name']) : '' ?></td></tr><?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </section>
</div>
<?php if ($events): ?>
<section class="card-box" style="margin-top:1.25rem"><h2 class="chart-title" style="padding:1rem 1.1rem 0">Уведомления ЮKassa по платежу</h2>
    <div class="table-wrap"><table class="table"><tbody><?php foreach ($events as $e): ?><tr><td class="nowrap"><?= $this->e($this->short(new \DateTimeImmutable((string) $e['received_at']))) ?></td><td><code><?= $this->e($e['event']) ?></code></td><td><?= $this->e($e['status']) ?></td><td class="muted small"><?= $this->e((string) $e['error']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
