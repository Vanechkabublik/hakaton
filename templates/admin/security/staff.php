<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\User\User> $staff */
$this->layout('layouts/admin');
?>
<?= $this->partial('admin/security/_tabs', ['tab' => $tab]) ?>
<p class="muted">Редакторы и администраторы обязаны использовать TOTP. Если сотрудник потерял телефон и коды восстановления, сбросьте ему 2FA — при следующем входе он настроит её заново.</p>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Сотрудник</th><th>Роль</th><th>2FA</th><th>Последний вход</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): ?>
        <tr><td><a href="/admin/users/<?= (int) $s->id ?>"><?= $this->e($s->name) ?></a><span class="sub"><?= $this->e($s->email) ?></span></td>
            <td><?= $this->e($s->role->label()) ?></td>
            <td><?php if ($s->hasTwoFactor()): ?><span class="badge badge-success">включена</span><?php elseif ($s->role->requiresTwoFactor()): ?><span class="badge badge-danger">требуется настроить</span><?php else: ?><span class="badge badge-muted">не настроена</span><?php endif; ?></td>
            <td class="nowrap"><?= $this->e($this->short($s->lastLoginAt)) ?></td>
            <td class="actions"><?php if ($s->hasTwoFactor()): ?><form method="post" action="/admin/security/staff/<?= (int) $s->id ?>/reset-2fa" data-confirm="Сбросить 2FA для <?= $this->e($s->email) ?>?"><?= $this->csrfField() ?><button class="btn btn-ghost btn-xs" type="submit">Сбросить 2FA</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
