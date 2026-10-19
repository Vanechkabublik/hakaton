<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Billing\Subscription> $items */
/** @var array<string,string> $filters */
$this->layout('layouts/admin');
$status = $filters['status'] ?? 'live';
?>
<nav class="tabs" aria-label="Статусы">
    <?php foreach (['live' => 'Активные', 'expired' => 'Истекшие', 'trialing' => 'Пробные', 'past_due' => 'Ожидают оплаты'] as $k => $l): ?><a href="/admin/subscriptions?status=<?= $k ?>" class="<?= $status === $k ? 'is-active' : '' ?>"><?= $l ?></a><?php endforeach; ?>
</nav>
<div class="toolbar"><form method="get"><input type="hidden" name="status" value="<?= $this->e($status) ?>">
    <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Email или имя">
    <select name="tier"><option value="">Любой уровень</option><option value="digital"<?= $this->selected($filters['tier'] ?? '', 'digital') ?>>Digital</option><option value="premium"<?= $this->selected($filters['tier'] ?? '', 'premium') ?>>Premium</option></select>
    <select name="source"><option value="">Любой источник</option><?php foreach (['payment' => 'Оплата', 'trial' => 'Пробный период', 'manual' => 'Выдана вручную', 'gift' => 'Подарок'] as $k => $l): ?><option value="<?= $k ?>"<?= $this->selected($filters['source'] ?? '', $k) ?>><?= $l ?></option><?php endforeach; ?></select>
    <button class="btn btn-ghost btn-sm" type="submit">Найти</button></form><span class="muted small">Найдено: <?= (int) $total ?></span></div>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Пользователь</th><th>Тариф</th><th>Статус</th><th>Источник</th><th>Период</th><th>Автопродление</th></tr></thead>
    <tbody>
    <?php foreach ($items as $s): ?>
        <tr><td><a href="/admin/users/<?= (int) $s->userId ?>"><?= $this->e((string) $s->userName) ?></a><span class="sub"><?= $this->e((string) $s->userEmail) ?></span></td>
            <td><?= $this->e((string) $s->planName) ?></td>
            <td><span class="badge badge-<?= $this->e($s->status->tone()) ?>"><?= $this->e($s->status->label()) ?></span></td>
            <td><?= $this->e(['payment' => 'оплата', 'trial' => 'пробный период', 'manual' => 'вручную', 'gift' => 'подарок', 'promo' => 'промокод'][$s->source] ?? $s->source) ?></td>
            <td class="nowrap"><?= $this->e($this->date($s->currentPeriodStart, false)) ?> — <?= $this->e($this->date($s->currentPeriodEnd)) ?></td>
            <td><?= $s->willRenew() ? 'да' : '<span class="muted">нет</span>' ?><?= $s->renewAttempts > 0 ? '<span class="sub">неудачных попыток: ' . (int) $s->renewAttempts . '</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="6" class="muted">Подписок нет.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/subscriptions', 'query' => $filters]) ?>
