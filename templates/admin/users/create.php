<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\User\Role> $roles */
$this->layout('layouts/admin');
?>
<form method="post" action="/admin/users" class="form-panel" novalidate>
    <?= $this->csrfField() ?>
    <p class="muted">Пользователь получит письмо со ссылкой, по которой задаст пароль. Для редакторов и администраторов двухфакторная аутентификация обязательна — её нужно будет настроить при первом входе.</p>
    <div class="form-grid">
        <div class="field"><label for="name">Имя</label><input id="name" type="text" name="name" value="<?= $this->old('name') ?>" required maxlength="120"><?= $this->error('name') ?></div>
        <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= $this->old('email') ?>" required><?= $this->error('email') ?></div>
        <div class="field"><label for="role">Роль</label><select id="role" name="role"><?php foreach ($roles as $r): ?><option value="<?= $r->value ?>"<?= $this->selected($this->old('role', 'author'), $r->value) ?>><?= $this->e($r->label()) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Создать и отправить приглашение</button><a class="btn btn-ghost" href="/admin/users">Отмена</a></div>
</form>
