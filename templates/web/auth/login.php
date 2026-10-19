<?php
/** @var \App\Core\View\View $this */
/** @var bool $yandexEnabled */
$this->layout('layouts/web');
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1>Вход</h1>
        <?php if ($yandexEnabled): ?>
            <a class="btn btn-yandex btn-block" href="/auth/yandex"><span class="ya-logo" aria-hidden="true">Я</span> Войти через Яндекс ID</a>
            <div class="divider"><span>или по email</span></div>
        <?php endif; ?>
        <form method="post" action="/login" novalidate>
            <?= $this->csrfField() ?>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $this->old('email') ?>" required autocomplete="username" autofocus class="<?= $this->hasError('email') ? 'has-error' : '' ?>">
                <?= $this->error('email') ?>
            </div>
            <div class="field">
                <label for="password">Пароль <a class="field-link" href="/forgot-password">Забыли пароль?</a></label>
                <input id="password" type="password" name="password" required autocomplete="current-password" class="<?= $this->hasError('password') ? 'has-error' : '' ?>">
                <?= $this->error('password') ?>
            </div>
            <label class="check"><input type="checkbox" name="remember" value="1"> Запомнить меня на этом устройстве</label>
            <button type="submit" class="btn btn-primary btn-block">Войти</button>
        </form>
        <p class="auth-switch">Нет аккаунта? <a href="/register">Зарегистрироваться</a></p>
    </div>
</div>
