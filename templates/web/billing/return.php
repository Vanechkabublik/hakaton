<?php
/** @var \App\Core\View\View $this */
/** @var \App\Billing\Payment $payment */
$this->layout('layouts/web');
$status = $payment->status;
?>
<div class="container auth-wrap">
    <div class="auth-card center" id="payment-state" data-payment-id="<?= (int) $payment->id ?>" data-final="<?= $status->isFinal() ? '1' : '0' ?>">
        <?php if ($status === \App\Billing\PaymentStatus::Succeeded): ?>
            <div class="state-icon state-ok" aria-hidden="true">✓</div>
            <h1>Оплата прошла</h1>
            <p><?= $payment->kind === \App\Billing\PaymentKind::Gift ? 'Подарок оплачен — получатель скоро получит письмо с кодом.' : 'Спасибо! Подписка активирована, платные материалы уже доступны.' ?></p>
            <p class="muted small">Квитанция доступна в <a href="/account/payments">истории платежей</a>.</p>
            <p><a class="btn btn-primary" href="/">Читать материалы</a> <a class="btn btn-ghost" href="/account/subscription">Моя подписка</a></p>
        <?php elseif ($status === \App\Billing\PaymentStatus::Canceled): ?>
            <div class="state-icon state-fail" aria-hidden="true">✕</div>
            <h1>Оплата не завершена</h1>
            <p class="muted"><?= $this->e($payment->cancelReason ? 'Платёж отклонён или отменён (' . $payment->cancelReason . ').' : 'Платёж отменён.') ?> Деньги не списаны.</p>
            <p><a class="btn btn-primary" href="<?= $payment->planId ? '/subscribe/checkout/' . (int) $payment->planId : '/subscribe' ?>">Попробовать снова</a></p>
        <?php else: ?>
            <div class="spinner" role="status" aria-label="Проверяем оплату"></div>
            <h1>Проверяем оплату…</h1>
            <p class="muted" id="payment-hint">Обычно это занимает несколько секунд. Не закрывайте страницу.</p>
        <?php endif; ?>
    </div>
</div>
<?php if (!$status->isFinal()): ?>
<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/billing.js') ?>" defer></script>
<?php $this->end(); ?>
<?php endif; ?>
