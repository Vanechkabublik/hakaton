<?php
/** @var \App\Core\View\View $this */
/** @var string $token */
$this->layout('layouts/web');
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1>Новый пароль</h1>
        <form method="post" action="/reset-password/<?= $this->e($token) ?>" novalidate>
            <?= $this->csrfField() ?>
            <div class="field">
                <label for="password">Новый пароль</label>
                <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus class="<?= $this->hasError('password') ? 'has-error' : '' ?>">
                <p class="hint">Не короче 8 символов, буквы и цифры</p>
                <?= $this->error('password') ?>
            </div>
            <div class="field">
                <label for="password_confirmation">Повторите пароль</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="<?= $this->hasError('password_confirmation') ? 'has-error' : '' ?>">
                <?= $this->error('password_confirmation') ?>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Сохранить пароль</button>
        </form>
    </div>
</div>
