<?php
/** @var \App\Core\View\View $this */
/** @var array<string,array<string,\App\Billing\Plan>> $grouped */
/** @var list<\App\Billing\PlanTier> $tiers */
/** @var ?\App\Billing\Subscription $current */
/** @var ?\App\Billing\Plan $trialPlan */
$this->layout('layouts/web', ['bodyClass' => 'page-plans']);
$periods = ['month' => 'Месяц', 'year' => 'Год'];
?>
<div class="container">
    <header class="plans-hero">
        <h1>Читайте всё без ограничений</h1>
        <p class="muted lead">Независимая журналистика держится на читателях. Выберите подходящий тариф — отменить можно в любой момент.</p>
        <?php if (!$available): ?><div class="notice notice-warning">Приём платежей временно недоступен. Загляните позже.</div><?php endif; ?>
        <div class="period-switch" role="tablist" aria-label="Период оплаты" id="period-switch">
            <?php foreach ($periods as $key => $label): ?>
                <button type="button" role="tab" class="period-btn<?= $key === 'month' ? ' is-active' : '' ?>" data-period="<?= $key ?>" aria-selected="<?= $key === 'month' ? 'true' : 'false' ?>"><?= $label ?><?= $key === 'year' ? ' <span class="save-badge">выгоднее</span>' : '' ?></button>
            <?php endforeach; ?>
        </div>
    </header>

    <?php if ($current): ?>
        <div class="notice">У вас уже есть подписка «<?= $this->e((string) $current->planName) ?>» до <?= $this->e($this->date($current->currentPeriodEnd)) ?>. Здесь можно продлить её или сменить тариф. <a href="/account/subscription">Управление подпиской</a></div>
    <?php endif; ?>

    <?php foreach ($periods as $periodKey => $periodLabel): ?>
        <div class="plans-grid" data-period-grid="<?= $periodKey ?>"<?= $periodKey === 'year' ? ' hidden' : '' ?>>
            <?php foreach ($tiers as $tier): $plan = $grouped[$tier->value][$periodKey] ?? null; if (!$plan) { continue; } $isCurrent = $current && $current->planId === $plan->id; ?>
                <article class="plan-card<?= $tier->value === 'premium' ? ' is-featured' : '' ?>">
                    <?php if ($tier->value === 'premium'): ?><span class="plan-ribbon">Максимум</span><?php endif; ?>
                    <h2><?= $this->e($tier->label()) ?></h2>
                    <p class="muted"><?= $this->e($plan->description) ?></p>
                    <p class="plan-price"><?= $this->e($this->money($plan->price)) ?><small> / <?= $this->e($plan->period->label()) ?></small></p>
                    <?php if ($plan->period === \App\Billing\PlanPeriod::Year): ?><p class="small muted">≈ <?= $this->e($this->money($plan->monthlyPrice())) ?> в месяц</p><?php endif; ?>
                    <ul class="plan-features">
                        <?php foreach ($plan->features as $feature): ?><li><?= $this->e($feature) ?></li><?php endforeach; ?>
                    </ul>
                    <a class="btn <?= $tier->value === 'premium' ? 'btn-primary' : 'btn-ghost' ?> btn-block btn-lg" href="/subscribe/checkout/<?= (int) $plan->id ?>"><?= $isCurrent ? 'Продлить' : ($current ? 'Перейти на тариф' : 'Оформить') ?></a>
                    <?php if ($plan->trialDays > 0 && !$current): ?><p class="small muted center"><?= (int) $plan->trialDays ?> дней бесплатно для новых читателей</p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($trialPlan): ?>
        <section class="trial-box">
            <div><h2>Не уверены? Попробуйте бесплатно</h2><p class="muted"><?= (int) $trialPlan->trialDays ?> дней полного доступа без привязки карты. Закончится сам — ничего списываться не будет.</p></div>
            <form method="post" action="/subscribe/trial/<?= (int) $trialPlan->id ?>"><?= $this->csrfField() ?><button type="submit" class="btn btn-primary btn-lg">Начать пробный период</button></form>
        </section>
    <?php endif; ?>

    <section class="plans-faq">
        <h2>Вопросы и ответы</h2>
        <details><summary>Как работает бесплатный лимит?</summary><p>Каждый месяц вам доступно <?= (int) $freeLimit ?> платных материалов бесплатно. Счётчик обновляется первого числа.</p></details>
        <details><summary>Как отменить подписку?</summary><p>В <a href="/account/subscription">личном кабинете</a> в один клик. Доступ сохранится до конца оплаченного периода, повторных списаний не будет.</p></details>
        <details><summary>Что такое автопродление?</summary><p>Если при оплате отметить автопродление, карта сохраняется в ЮKassa, и за день до окончания периода мы напомним о списании. Выключить автопродление можно в любой момент.</p></details>
        <details><summary>Безопасна ли оплата?</summary><p>Платёж проходит на стороне ЮKassa. Данные карты нам не передаются и на нашем сервере не хранятся.</p></details>
        <details><summary>Можно ли подарить подписку?</summary><p>Да. <a href="/gift">Оформите подарок</a> — получатель получит код на email.</p></details>
    </section>
</div>
<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/billing.js') ?>" defer></script>
<?php $this->end(); ?>
