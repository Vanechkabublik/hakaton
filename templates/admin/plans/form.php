<?php
/** @var \App\Core\View\View $this */
/** @var \App\Billing\Plan $plan */
/** @var list<\App\Billing\PlanTier> $tiers */
/** @var list<\App\Billing\PlanPeriod> $periods */
$this->layout('layouts/admin');
$isNew = $plan->id === 0;
?>
<form method="post" action="/admin/plans<?= $isNew ? '' : '/' . (int) $plan->id ?>" class="form-panel" novalidate>
    <?= $this->csrfField() ?>
    <div class="form-grid">
        <div class="field"><label for="name">Название</label><input id="name" type="text" name="name" value="<?= $this->old('name', $plan->name) ?>" required maxlength="100"><?= $this->error('name') ?></div>
        <div class="field"><label for="code">Код (латиница, уникальный)</label><input id="code" type="text" name="code" value="<?= $this->old('code', $plan->code) ?>" required maxlength="40" pattern="[a-z0-9-]+"><?= $this->error('code') ?></div>
        <div class="field"><label for="tier">Уровень доступа</label><select id="tier" name="tier"><?php foreach ($tiers as $t): ?><option value="<?= $t->value ?>"<?= $this->selected($this->old('tier', $plan->tier->value), $t->value) ?>><?= $this->e($t->label()) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="period">Период</label><select id="period" name="period"><?php foreach ($periods as $p): ?><option value="<?= $p->value ?>"<?= $this->selected($this->old('period', $plan->period->value), $p->value) ?>><?= $this->e($p->adjective()) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="price">Цена, ₽</label><input id="price" type="text" name="price" inputmode="decimal" value="<?= $this->old('price', \App\Billing\Money::toInput($plan->price)) ?>" required><?= $this->error('price') ?></div>
        <div class="field"><label for="trial_days">Пробный период, дней (0 — нет)</label><input id="trial_days" type="number" name="trial_days" min="0" max="60" value="<?= $this->old('trial_days', (string) $plan->trialDays) ?>"><?= $this->error('trial_days') ?></div>
        <div class="field span-all"><label for="description">Краткое описание</label><input id="description" type="text" name="description" value="<?= $this->old('description', $plan->description) ?>" maxlength="500"></div>
        <div class="field span-all"><label for="features">Преимущества (по одному в строке)</label><textarea id="features" name="features" rows="5"><?= $this->e(implode("\n", $plan->features)) ?></textarea></div>
        <div class="field"><label for="sort_order">Порядок показа</label><input id="sort_order" type="number" name="sort_order" value="<?= $this->old('sort_order', (string) $plan->sortOrder) ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= $this->checked($isNew || $plan->isActive) ?>> Продаётся (показывать на странице тарифов)</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Сохранить</button><a class="btn btn-ghost" href="/admin/plans">Отмена</a>
        <?php if (!$isNew): ?><button class="btn btn-ghost" style="color:var(--danger)" type="submit" formaction="/admin/plans/<?= (int) $plan->id ?>/delete" data-confirm-click="Удалить тариф?">Удалить</button><?php endif; ?></div>
</form>
