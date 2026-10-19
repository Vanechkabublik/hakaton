<?php
/** @var \App\Core\View\View $this */
/** @var \App\User\User $subject */
/** @var ?\App\Billing\Subscription $live */
/** @var list<\App\Billing\Subscription> $subscriptions */
/** @var list<\App\Billing\Payment> $payments */
/** @var list<\App\Billing\Plan> $plans */
/** @var list<array<string,mixed>> $audit */
$this->layout('layouts/admin');
$self = $subject->id === $user->id;
?>
<?php $this->start('actions'); ?><a class="btn btn-ghost btn-sm" href="/admin/users">← К списку</a><?php $this->end(); ?>
<div class="two-col">
    <section class="form-panel" style="max-width:none">
        <h2><?= $this->e($subject->name) ?> <span class="badge badge-<?= $subject->isActive() ? 'success' : 'danger' ?>"><?= $this->e($subject->status->label()) ?></span></h2>
        <table class="kv-table">
            <tr><th>Email</th><td><?= $this->e($subject->email) ?> <?= $subject->isEmailVerified() ? '✓' : '<span class="pill">не подтверждён</span>' ?></td></tr>
            <tr><th>Роль</th><td><?= $this->e($subject->role->label()) ?></td></tr>
            <tr><th>Регистрация</th><td><?= $this->e($this->dateTime($subject->createdAt)) ?></td></tr>
            <tr><th>Последний вход</th><td><?= $this->e($this->dateTime($subject->lastLoginAt)) ?></td></tr>
            <tr><th>2FA</th><td><?= $subject->hasTwoFactor() ? 'включена' : 'выключена' ?></td></tr>
            <tr><th>Рассылки</th><td>дайджест: <?= $this->e($subject->digestFrequency) ?>; маркетинг: <?= $subject->marketingOptIn ? 'да' : 'нет' ?></td></tr>
            <?php if ($subject->blockedReason): ?><tr><th>Причина блокировки</th><td><?= $this->e($subject->blockedReason) ?></td></tr><?php endif; ?>
            <tr><th>Комментариев</th><td><?= (int) $comments['total'] ?></td></tr>
        </table>
        <div class="form-actions">
            <form method="post" action="/admin/users/<?= (int) $subject->id ?>/send-reset"><?= $this->csrfField() ?><button class="btn btn-ghost btn-sm" type="submit">Отправить ссылку для сброса пароля</button></form>
            <?php if ($subject->hasTwoFactor()): ?><form method="post" action="/admin/users/<?= (int) $subject->id ?>/reset-2fa" data-confirm="Сбросить 2FA пользователя?"><?= $this->csrfField() ?><button class="btn btn-ghost btn-sm" type="submit">Сбросить 2FA</button></form><?php endif; ?>
        </div>
    </section>

    <section class="form-panel" style="max-width:none">
        <h2>Управление</h2>
        <?php if (!$self): ?>
            <form method="post" action="/admin/users/<?= (int) $subject->id ?>/role" class="toolbar">
                <?= $this->csrfField() ?>
                <select name="role" aria-label="Роль"><?php foreach ($roles as $r): ?><option value="<?= $r->value ?>"<?= $this->selected($subject->role->value, $r->value) ?>><?= $this->e($r->label()) ?></option><?php endforeach; ?></select>
                <button class="btn btn-ghost btn-sm" type="submit">Сменить роль</button>
            </form>
            <?php if ($subject->isActive()): ?>
                <form method="post" action="/admin/users/<?= (int) $subject->id ?>/block" class="toolbar" data-confirm="Заблокировать пользователя? Все его сеансы будут завершены.">
                    <?= $this->csrfField() ?>
                    <input type="text" name="reason" placeholder="Причина блокировки" maxlength="500" class="grow">
                    <button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">Заблокировать</button>
                </form>
            <?php else: ?>
                <form method="post" action="/admin/users/<?= (int) $subject->id ?>/unblock"><?= $this->csrfField() ?><button class="btn btn-primary btn-sm" type="submit">Разблокировать</button></form>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">Это вы — роль и блокировку менять нельзя.</p>
        <?php endif; ?>

        <h2 style="margin-top:1.5rem">Подписка</h2>
        <?php if ($live): ?>
            <p><span class="badge badge-<?= $this->e($live->status->tone()) ?>"><?= $this->e($live->status->label()) ?></span> <?= $this->e((string) $live->planName) ?> до <?= $this->e($this->dateTime($live->currentPeriodEnd)) ?>
                <span class="muted small">(<?= $this->e($live->source) ?><?= $live->willRenew() ? ', автопродление' : '' ?>)</span></p>
            <form method="post" action="/admin/users/<?= (int) $subject->id ?>/revoke" data-confirm="Отозвать подписку немедленно?"><?= $this->csrfField() ?><button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">Отозвать подписку</button></form>
        <?php else: ?><p class="muted">Активной подписки нет.</p><?php endif; ?>
        <form method="post" action="/admin/users/<?= (int) $subject->id ?>/grant" class="toolbar" style="margin-top:1rem">
            <?= $this->csrfField() ?>
            <select name="plan_id" aria-label="Тариф"><?php foreach ($plans as $p): ?><option value="<?= (int) $p->id ?>"><?= $this->e($p->title()) ?></option><?php endforeach; ?></select>
            <label class="small muted" for="until">до</label><input id="until" type="date" name="until" aria-label="Дата окончания (пусто — один период тарифа)">
            <button class="btn btn-primary btn-sm" type="submit"><?= $live ? 'Заменить / продлить вручную' : 'Выдать подписку' ?></button>
        </form>
        <p class="help">Пустая дата — на один период выбранного тарифа. Ручная подписка не продлевается автоматически.</p>
    </section>
</div>

<div class="two-col" style="margin-top:1.25rem">
    <section class="card-box">
        <h2 class="chart-title" style="padding:1rem 1.1rem 0">Платежи</h2>
        <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($payments as $p): ?>
                <tr><td><a href="/admin/payments/<?= (int) $p->id ?>">#<?= (int) $p->id ?></a><span class="sub"><?= $this->e($this->short($p->createdAt)) ?></span></td><td><?= $this->e($p->description) ?></td><td class="num"><?= $this->e($this->money($p->amount, true)) ?></td><td><span class="badge badge-<?= $this->e($p->displayTone()) ?>"><?= $this->e($p->displayStatus()) ?></span></td></tr>
            <?php endforeach; ?>
            <?php if ($payments === []): ?><tr><td class="muted">Платежей нет.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="card-box">
        <h2 class="chart-title" style="padding:1rem 1.1rem 0">История подписок</h2>
        <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($subscriptions as $s): ?>
                <tr><td><?= $this->e((string) $s->planName) ?><span class="sub"><?= $this->e($s->source) ?></span></td><td><span class="badge badge-<?= $this->e($s->status->tone()) ?>"><?= $this->e($s->status->label()) ?></span></td><td><?= $this->e($this->date($s->startedAt)) ?> — <?= $this->e($this->date($s->currentPeriodEnd)) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($subscriptions === []): ?><tr><td class="muted">Подписок не было.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>

<section class="card-box" style="margin-top:1.25rem">
    <h2 class="chart-title" style="padding:1rem 1.1rem 0">История действий</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Действие</th><th>IP</th><th>Детали</th></tr></thead><tbody>
        <?php foreach ($audit as $a): ?><tr><td class="nowrap"><?= $this->e($this->short(new \DateTimeImmutable((string) $a['created_at']))) ?></td><td><code><?= $this->e($a['action']) ?></code></td><td><?= $this->e((string) $a['ip']) ?></td><td class="muted small"><?= $this->e($this->truncate((string) $a['meta'], 120)) ?></td></tr><?php endforeach; ?>
        <?php if ($audit === []): ?><tr><td colspan="4" class="muted">Записей нет.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
