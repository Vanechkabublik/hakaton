<?php
/** @var \App\Core\View\View $this */
/** @var \App\Billing\Plan $plan */
/** @var \App\Billing\Quote $quote */
/** @var bool $available */
/** @var bool $recurring */
/** @var string $defaultMode */
$this->layout('layouts/web', ['bodyClass' => 'page-checkout']);
$kindText = [
    'initial' => 'Новая подписка',
    'extension' => 'Продление текущей подписки',
    'upgrade' => 'Смена тарифа с зачётом остатка',
][$quote->kind->value] ?? '';
?>
<div class="container container-narrow">
    <nav class="breadcrumbs" aria-label="Хлебные крошки"><a href="/subscribe">Тарифы</a><span aria-hidden="true">›</span><span>Оформление</span></nav>
    <h1 class="page-title">Оформление подписки</h1>

    <div class="checkout-grid">
        <section class="panel" aria-labelledby="sum-title">
            <h2 class="panel-title" id="sum-title">Ваш заказ</h2>
            <p><strong><?= $this->e($plan->title()) ?></strong><br><span class="muted"><?= $this->e($plan->description) ?></span></p>
            <p class="small muted"><?= $this->e($kindText) ?></p>
            <dl class="sum-list" id="sum-list">
                <dt>Стоимость тарифа</dt><dd id="sum-price"><?= $this->e($this->money($quote->price)) ?></dd>
                <?php if ($quote->credit > 0): ?><dt>Зачёт остатка текущей подписки</dt><dd id="sum-credit">−<?= $this->e($this->money($quote->credit)) ?></dd><?php endif; ?>
                <dt id="sum-discount-label"<?= $quote->discount > 0 ? '' : ' hidden' ?>>Скидка по промокоду</dt><dd id="sum-discount"<?= $quote->discount > 0 ? '' : ' hidden' ?>>−<?= $this->e($this->money($quote->discount)) ?></dd>
                <dt class="sum-total">К оплате</dt><dd class="sum-total" id="sum-total"><?= $this->e($this->money($quote->total, true)) ?></dd>
            </dl>
            <?php if ($quote->isScheduledChange): ?>
                <div class="notice notice-info">Вы переходите на более дешёвый тариф. Платить сейчас не нужно: текущий период вы дочитаете на прежних условиях, а новый тариф заработает со следующего.</div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="pay-title">
            <h2 class="panel-title" id="pay-title">Оплата</h2>
            <?php if (!$available && !$quote->isScheduledChange): ?>
                <div class="notice notice-warning">Приём платежей временно недоступен. Попробуйте позже.</div>
            <?php else: ?>
                <form method="post" action="/subscribe/checkout/<?= (int) $plan->id ?>" id="checkout-form" data-quote-url="/subscribe/checkout/<?= (int) $plan->id ?>/quote" novalidate>
                    <?= $this->csrfField() ?>
                    <?php if (!$quote->isScheduledChange): ?>
                        <div class="field">
                            <label for="promo">Промокод</label>
                            <div class="input-group">
                                <input id="promo" type="text" name="promo" value="<?= $this->e($promoCode) ?>" autocomplete="off" placeholder="Есть промокод?" maxlength="40">
                                <button type="button" class="btn btn-ghost" id="promo-apply">Применить</button>
                            </div>
                            <p class="field-error" id="promo-error" role="alert"<?= $quote->promoError ? '' : ' hidden' ?>><?= $this->e((string) $quote->promoError) ?></p>
                            <p class="hint" id="promo-ok" <?= $quote->promo ? '' : 'hidden' ?>>Промокод применён <?= $quote->promo ? '(' . $this->e($quote->promo->label()) . ')' : '' ?></p>
                        </div>
                        <?php if ($recurring): ?>
                            <label class="check">
                                <input type="checkbox" name="auto_renew" value="1" checked>
                                <span><strong>Автопродление.</strong> Сохранить способ оплаты и продлевать подписку автоматически (<?= $this->e($this->money($plan->price)) ?> каждый <?= $this->e($plan->period->label()) ?>). За день до списания мы напомним по email. Отключить можно в любой момент в личном кабинете.</span>
                            </label>
                        <?php endif; ?>
                        <label class="check"><input type="checkbox" name="agree" value="1" required id="agree"> <span>Я принимаю <a href="/terms" target="_blank">пользовательское соглашение</a> и <a href="/privacy" target="_blank">политику конфиденциальности</a></span></label>
                    <?php endif; ?>

                    <?php if ($quote->isScheduledChange): ?>
                        <button type="submit" class="btn btn-primary btn-block btn-lg">Подтвердить смену тарифа</button>
                    <?php else: ?>
                        <div class="pay-buttons">
                            <button type="submit" name="mode" value="redirect" class="btn btn-primary btn-lg btn-block" id="pay-redirect">Перейти к оплате</button>
                            <button type="submit" name="mode" value="embedded" class="btn btn-ghost btn-block" id="pay-embedded">Оплатить на этой странице</button>
                        </div>
                        <p class="small muted center">Оплата картой, СБП и другими способами через ЮKassa. Данные карты не попадают на наши серверы.</p>
                    <?php endif; ?>
                </form>
                <?php if ($canTrial && !$quote->isScheduledChange): ?>
                    <div class="divider"><span>или</span></div>
                    <form method="post" action="/subscribe/trial/<?= (int) $plan->id ?>"><?= $this->csrfField() ?><button type="submit" class="btn btn-ghost btn-block">Попробовать <?= (int) $plan->trialDays ?> дней бесплатно</button></form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/billing.js') ?>" defer></script>
<?php $this->end(); ?>
