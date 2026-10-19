<?php
/** @var \App\Core\View\View $this */
/** @var \App\Billing\PromoCode $promo */
/** @var list<\App\Billing\Plan> $plans */
$this->layout('layouts/admin');
$isNew = $promo->id === 0;
?>
<form method="post" action="/admin/promocodes<?= $isNew ? '' : '/' . (int) $promo->id ?>" class="form-panel" novalidate>
    <?= $this->csrfField() ?>
    <div class="form-grid">
        <div class="field"><label for="code">Код</label><input id="code" type="text" name="code" value="<?= $this->old('code', $promo->code) ?>" required maxlength="40" style="text-transform:uppercase"><?= $this->error('code') ?></div>
        <div class="field"><label for="description">Описание (для себя)</label><input id="description" type="text" name="description" value="<?= $this->old('description', $promo->description) ?>" maxlength="200"></div>
        <div class="field"><label for="discount_type">Тип скидки</label><select id="discount_type" name="discount_type"><option value="percent"<?= $this->selected($this->old('discount_type', $promo->discountType), 'percent') ?>>Процент</option><option value="fixed"<?= $this->selected($this->old('discount_type', $promo->discountType), 'fixed') ?>>Фиксированная сумма, ₽</option></select></div>
        <div class="field"><label for="discount_value">Размер скидки</label><input id="discount_value" type="text" name="discount_value" inputmode="decimal" value="<?= $this->old('discount_value', $promo->discountType === 'percent' ? (string) $promo->discountValue : \App\Billing\Money::toInput($promo->discountValue)) ?>" required><?= $this->error('discount_value') ?></div>
        <div class="field"><label for="max_uses">Всего использований (пусто — без лимита)</label><input id="max_uses" type="number" name="max_uses" min="1" value="<?= $this->old('max_uses', $promo->maxUses !== null ? (string) $promo->maxUses : '') ?>"></div>
        <div class="field"><label for="per_user_limit">На одного пользователя</label><input id="per_user_limit" type="number" name="per_user_limit" min="1" value="<?= $this->old('per_user_limit', (string) $promo->perUserLimit) ?>"></div>
        <div class="field"><label for="valid_from">Действует с</label><input id="valid_from" type="date" name="valid_from" value="<?= $promo->validFrom ? $this->e(\App\Support\Format::local($promo->validFrom)->format('Y-m-d')) : '' ?>"></div>
        <div class="field"><label for="valid_until">Действует по (включительно)</label><input id="valid_until" type="date" name="valid_until" value="<?= $promo->validUntil ? $this->e(\App\Support\Format::local($promo->validUntil)->format('Y-m-d')) : '' ?>"></div>
    </div>
    <fieldset class="field"><legend>Тарифы (ничего не отмечено — все)</legend>
        <?php foreach ($plans as $p): ?><label class="check"><input type="checkbox" name="plan_ids[]" value="<?= (int) $p->id ?>"<?= $this->checked(in_array($p->id, $promo->planIds, true)) ?>> <?= $this->e($p->title()) ?> — <?= $this->e($this->money($p->price)) ?></label><?php endforeach; ?></fieldset>
    <label class="check"><input type="checkbox" name="first_payment_only" value="1"<?= $this->checked($isNew || $promo->firstPaymentOnly) ?>> Только на первую оплату (для тех, у кого ещё не было платежей)</label>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= $this->checked($isNew || $promo->isActive) ?>> Активен</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Сохранить</button><a class="btn btn-ghost" href="/admin/promocodes">Отмена</a>
        <?php if (!$isNew): ?><button class="btn btn-ghost" style="color:var(--danger)" type="submit" formaction="/admin/promocodes/<?= (int) $promo->id ?>/delete" data-confirm-click="Удалить промокод?">Удалить</button><?php endif; ?></div>
</form>
