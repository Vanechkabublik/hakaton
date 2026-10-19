<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\User\User> $items */
/** @var array<int,\App\Billing\Subscription> $live */
/** @var array<string,string> $filters */
$this->layout('layouts/admin');
$qs = http_build_query($filters);
?>
<?php $this->start('actions'); ?>
    <a class="btn btn-ghost btn-sm" href="/admin/users/export<?= $qs ? '?' . $this->e($qs) : '' ?>">Экспорт CSV</a>
    <a class="btn btn-primary btn-sm" href="/admin/users/create">+ Сотрудник</a>
<?php $this->end(); ?>
<div class="toolbar">
    <form method="get" action="/admin/users">
        <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Email или имя">
        <select name="role" aria-label="Роль"><option value="">Все роли</option><?php foreach (\App\User\Role::cases() as $r): ?><option value="<?= $r->value ?>"<?= $this->selected($filters['role'] ?? '', $r->value) ?>><?= $this->e($r->label()) ?></option><?php endforeach; ?></select>
        <select name="status" aria-label="Статус"><option value="">Любой статус</option><?php foreach (\App\User\UserStatus::cases() as $s): ?><option value="<?= $s->value ?>"<?= $this->selected($filters['status'] ?? '', $s->value) ?>><?= $this->e($s->label()) ?></option><?php endforeach; ?></select>
        <select name="subscription" aria-label="Подписка"><option value="">Любая подписка</option>
            <?php foreach (['active' => 'Есть подписка', 'trial' => 'Пробный период', 'expired' => 'Истекла', 'none' => 'Никогда не было'] as $k => $l): ?><option value="<?= $k ?>"<?= $this->selected($filters['subscription'] ?? '', $k) ?>><?= $l ?></option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm" type="submit">Найти</button>
        <?php if ($filters): ?><a class="btn btn-ghost btn-sm" href="/admin/users">Сбросить</a><?php endif; ?>
    </form>
    <span class="muted small">Найдено: <?= (int) $total ?></span>
</div>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Пользователь</th><th>Роль</th><th>Статус</th><th>Подписка</th><th>Регистрация</th><th>Последний вход</th></tr></thead>
    <tbody>
    <?php foreach ($items as $u): $s = $live[$u->id] ?? null; ?>
        <tr<?= !$u->isActive() ? ' class="muted-row"' : '' ?>>
            <td><a href="/admin/users/<?= (int) $u->id ?>"><?= $this->e($u->name) ?></a><span class="sub"><?= $this->e($u->email) ?><?= $u->isEmailVerified() ? '' : ' · не подтверждён' ?></span></td>
            <td><?= $this->e($u->role->label()) ?><?= $u->hasTwoFactor() ? ' <span class="pill" title="2FA включена">2FA</span>' : '' ?></td>
            <td><span class="badge badge-<?= $u->isActive() ? 'success' : 'danger' ?>"><?= $this->e($u->status->label()) ?></span></td>
            <td><?php if ($s): ?><span class="badge badge-<?= $this->e($s->status->tone()) ?>"><?= $this->e($s->status->label()) ?></span><span class="sub"><?= $this->e((string) $s->planName) ?> до <?= $this->e($this->date($s->currentPeriodEnd, false)) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="nowrap"><?= $this->e($this->short($u->createdAt)) ?></td>
            <td class="nowrap"><?= $this->e($this->short($u->lastLoginAt)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="6" class="muted">Никого не найдено.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/users', 'query' => $filters]) ?>
