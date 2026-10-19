<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Billing\Plan> $plans */
/** @var bool $available */
$this->layout('layouts/web');
?>
<div class="container container-narrow">
    <h1 class="page-title">Подарить подписку 🎁</h1>
    <p class="muted lead">Получатель получит письмо с личным кодом и сам активирует подписку в своём аккаунте. Автопродление для подарков не включается.</p>
    <?php if (!$user): ?><div class="notice"><a href="/login">Войдите</a> или <a href="/register">зарегистрируйтесь</a>, чтобы оформить подарок.</div><?php endif; ?>
    <?php if (!$available): ?><div class="notice notice-warning">Приём платежей временно недоступен.</div><?php endif; ?>

    <form method="post" action="/gift" class="panel card-form" novalidate>
        <?= $this->csrfField() ?>
        <fieldset class="field">
            <legend>Тариф</legend>
            <?php foreach ($plans as $i => $plan): ?>
                <label class="radio radio-card"><input type="radio" name="plan_id" value="<?= (int) $plan->id ?>"<?= $this->checked($i === 0 || $this->old('plan_id') == $plan->id) ?>>
                    <span><strong><?= $this->e($plan->title()) ?></strong> — <?= $this->e($this->money($plan->price)) ?><br><span class="muted small"><?= $this->e($plan->description) ?></span></span></label>
            <?php endforeach; ?>
            <?= $this->error('plan_id') ?>
        </fieldset>
        <div class="field">
            <label for="recipient_email">Email получателя</label>
            <input id="recipient_email" type="email" name="recipient_email" value="<?= $this->old('recipient_email') ?>" required class="<?= $this->hasError('recipient_email') ? 'has-error' : '' ?>">
            <?= $this->error('recipient_email') ?>
        </div>
        <div class="field">
            <label for="recipient_name">Имя получателя (необязательно)</label>
            <input id="recipient_name" type="text" name="recipient_name" value="<?= $this->old('recipient_name') ?>" maxlength="120">
        </div>
        <div class="field">
            <label for="message">Поздравление (необязательно)</label>
            <textarea id="message" name="message" rows="3" maxlength="1000"><?= $this->old('message') ?></textarea>
        </div>
        <div class="field">
            <label for="promo">Промокод</label>
            <input id="promo" type="text" name="promo" value="<?= $this->old('promo') ?>" maxlength="40" autocomplete="off">
        </div>
        <?php if ($user): ?>
            <div class="pay-buttons">
                <button type="submit" name="mode" value="redirect" class="btn btn-primary btn-lg btn-block">Перейти к оплате</button>
                <button type="submit" name="mode" value="embedded" class="btn btn-ghost btn-block">Оплатить на этой странице</button>
            </div>
        <?php endif; ?>
    </form>
    <p class="center"><a href="/gift/redeem">У меня есть код подарка</a></p>
</div>
