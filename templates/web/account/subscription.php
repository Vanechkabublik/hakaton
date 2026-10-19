<?php
/** @var \App\Core\View\View $this */
/** @var ?\App\Billing\Subscription $subscription */
/** @var ?\App\Billing\Plan $currentPlan */
/** @var ?\App\Billing\Plan $pendingPlan */
/** @var list<\App\Billing\Plan> $plans */
/** @var list<\App\Billing\Subscription> $history */
/** @var list<\App\Billing\Gift> $gifts */
$this->layout('layouts/account');
?>
<h1>Моя подписка</h1>

<?php if ($subscription): ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Тариф «<?= $this->e((string) $subscription->planName) ?>»</h2>
                <p><span class="badge badge-<?= $this->e($subscription->status->tone()) ?>"><?= $this->e($subscription->status->label()) ?></span>
                    <?php if ($subscription->source !== 'payment'): ?><span class="muted small"> · <?= $this->e(['trial' => 'пробный период', 'manual' => 'выдана редакцией', 'gift' => 'подарок', 'promo' => 'по промокоду'][$subscription->source] ?? $subscription->source) ?></span><?php endif; ?></p>
            </div>
            <?php if ($currentPlan): ?><div class="price-tag"><?= $this->e($this->money($currentPlan->price)) ?><small>/ <?= $this->e($currentPlan->period->label()) ?></small></div><?php endif; ?>
        </div>
        <dl class="kv">
            <dt>Действует до</dt><dd><?= $this->e($this->dateTime($subscription->currentPeriodEnd)) ?></dd>
            <?php if ($subscription->status === \App\Billing\SubscriptionStatus::PastDue): ?>
                <dt>Льготный период до</dt><dd><?= $this->e($this->dateTime($subscription->graceUntil ?? $subscription->currentPeriodEnd)) ?></dd>
            <?php endif; ?>
            <dt>Автопродление</dt>
            <dd><?php if ($subscription->willRenew()): ?>включено — следующее списание <?= $this->e($this->date($subscription->currentPeriodEnd)) ?><?= $currentPlan ? ' · ' . $this->e($this->money(($pendingPlan ?? $currentPlan)->price)) : '' ?><?php else: ?>выключено<?php endif; ?></dd>
            <?php if ($subscription->paymentMethodTitle): ?><dt>Способ оплаты</dt><dd><?= $this->e($subscription->paymentMethodTitle) ?></dd><?php endif; ?>
            <?php if ($pendingPlan): ?><dt>Со следующего периода</dt><dd>тариф «<?= $this->e($pendingPlan->title()) ?>»</dd><?php endif; ?>
        </dl>

        <div class="form-actions">
            <?php if ($currentPlan): ?><a class="btn btn-primary" href="/subscribe/checkout/<?= (int) $currentPlan->id ?>">Продлить на <?= $this->e($currentPlan->period->label() === 'месяц' ? 'месяц' : 'год') ?></a><?php endif; ?>
            <?php if ($subscription->paymentMethodId): ?>
                <form method="post" action="/account/subscription/auto-renew" class="inline-form"><?= $this->csrfField() ?>
                    <input type="hidden" name="enabled" value="<?= $subscription->willRenew() ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-ghost"><?= $subscription->willRenew() ? 'Выключить автопродление' : 'Включить автопродление' ?></button>
                </form>
                <form method="post" action="/account/subscription/method/remove" class="inline-form" data-confirm="Удалить сохранённую карту?"><?= $this->csrfField() ?><button type="submit" class="btn btn-ghost">Удалить карту</button></form>
            <?php endif; ?>
            <?php if ($pendingPlan): ?>
                <form method="post" action="/account/subscription/pending-change/cancel" class="inline-form"><?= $this->csrfField() ?><button type="submit" class="btn btn-ghost">Отменить смену тарифа</button></form>
            <?php endif; ?>
        </div>

        <?php if ($subscription->willRenew() || $subscription->status === \App\Billing\SubscriptionStatus::PastDue): ?>
            <details class="cancel-box">
                <summary>Отменить подписку</summary>
                <form method="post" action="/account/subscription/cancel" class="card-form" data-confirm="Отменить подписку? Доступ сохранится до конца оплаченного периода.">
                    <?= $this->csrfField() ?>
                    <p class="muted">Автопродление будет выключено, доступ сохранится до <?= $this->e($this->date($subscription->currentPeriodEnd)) ?>. Расскажите, почему вы уходите — это поможет нам стать лучше.</p>
                    <div class="field"><label for="reason" class="visually-hidden">Причина</label>
                        <select id="reason" name="reason">
                            <option value="">Предпочитаю не отвечать</option><option>Дорого</option><option>Мало интересных материалов</option><option>Не хватает времени читать</option><option>Перехожу к другому изданию</option><option>Другое</option>
                        </select></div>
                    <button type="submit" class="btn btn-danger">Отменить подписку</button>
                </form>
            </details>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2 class="panel-title">Сменить тариф</h2>
        <p class="muted">Повышение тарифа вступает в силу сразу, остаток оплаченного периода засчитывается в стоимость. Понижение — со следующего периода.</p>
        <div class="mini-plans">
            <?php foreach ($plans as $plan): if ($currentPlan && $plan->id === $currentPlan->id) { continue; } ?>
                <a class="mini-plan" href="/subscribe/checkout/<?= (int) $plan->id ?>"><strong><?= $this->e($plan->title()) ?></strong><span><?= $this->e($this->money($plan->price)) ?> / <?= $this->e($plan->period->label()) ?></span></a>
            <?php endforeach; ?>
        </div>
    </section>
<?php else: ?>
    <section class="panel">
        <h2 class="panel-title">Подписки нет</h2>
        <p class="muted">Оформите подписку и читайте все материалы без ограничений.</p>
        <div class="form-actions">
            <a class="btn btn-primary" href="/subscribe">Выбрать тариф</a>
            <?php if ($canTrial && $trialPlan): ?>
                <form method="post" action="/subscribe/trial/<?= (int) $trialPlan->id ?>" class="inline-form"><?= $this->csrfField() ?><button type="submit" class="btn btn-ghost">Попробовать <?= (int) $trialPlan->trialDays ?> дней бесплатно</button></form>
            <?php endif; ?>
            <a class="btn btn-ghost" href="/gift/redeem">Активировать подарок</a>
        </div>
    </section>
<?php endif; ?>

<?php if (count($history) > 1 || ($history !== [] && !$subscription)): ?>
<section class="panel">
    <h2 class="panel-title">История подписок</h2>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Тариф</th><th>Статус</th><th>Период</th></tr></thead>
        <tbody><?php foreach ($history as $item): ?>
            <tr><td><?= $this->e((string) $item->planName) ?></td><td><span class="badge badge-<?= $this->e($item->status->tone()) ?>"><?= $this->e($item->status->label()) ?></span></td><td><?= $this->e($this->date($item->startedAt)) ?> — <?= $this->e($this->date($item->currentPeriodEnd)) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-head"><h2 class="panel-title">Подарочные подписки</h2><a class="btn btn-ghost btn-sm" href="/gift">Подарить подписку</a></div>
    <?php if ($gifts === []): ?>
        <p class="muted">Вы пока ничего не дарили.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Получатель</th><th>Тариф</th><th>Код</th><th>Статус</th></tr></thead>
            <tbody><?php foreach ($gifts as $gift): ?>
                <tr><td><?= $this->e($gift->recipientEmail) ?></td><td><?= $this->e((string) $gift->planName) ?></td>
                    <td><code><?= $gift->status === 'pending' ? '—' : $this->e($gift->code) ?></code></td>
                    <td><?= $this->e(['pending' => 'ожидает оплаты', 'paid' => 'оплачен, не активирован', 'redeemed' => 'активирован', 'expired' => 'истёк'][$gift->status] ?? $gift->status) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
