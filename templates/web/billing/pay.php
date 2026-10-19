<?php
/** @var \App\Core\View\View $this */
/** @var \App\Billing\Payment $payment */
/** @var string $token */
/** @var string $returnUrl */
$this->layout('layouts/web');
?>
<div class="container container-narrow">
    <h1 class="page-title">Оплата подписки</h1>
    <p class="muted"><?= $this->e($payment->description) ?> — <strong><?= $this->e($this->money($payment->amount, true)) ?></strong></p>
    <div id="payment-form" class="widget-box" data-token="<?= $this->e($token) ?>" data-return-url="<?= $this->e($returnUrl) ?>"></div>
    <p class="small muted">Не получается? <a href="/payment/return/<?= (int) $payment->id ?>">Проверить статус платежа</a> или <a href="/subscribe">вернуться к тарифам</a>.</p>
</div>
<?php $this->start('scripts'); ?>
<script src="https://yookassa.ru/checkout-widget/v1/checkout-widget.js" nonce="<?= $this->e($nonce) ?>"></script>
<script src="<?= $this->asset('js/billing.js') ?>" defer></script>
<?php $this->end(); ?>
