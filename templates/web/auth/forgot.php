<?php
/** @var \App\Core\View\View $this */
$this->layout('layouts/web');
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1>Восстановление пароля</h1>
        <p class="muted">Укажите email — мы отправим ссылку для создания нового пароля.</p>
        <form method="post" action="/forgot-password" novalidate>
            <?= $this->csrfField() ?>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $this->old('email') ?>" required autofocus autocomplete="email" class="<?= $this->hasError('email') ? 'has-error' : '' ?>">
                <?= $this->error('email') ?>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Отправить ссылку</button>
        </form>
        <p class="auth-switch"><a href="/login">Вернуться ко входу</a></p>
    </div>
</div>
