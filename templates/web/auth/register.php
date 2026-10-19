<?php
/** @var \App\Core\View\View $this */
/** @var bool $yandexEnabled */
$this->layout('layouts/web');
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1>Регистрация</h1>
        <?php if ($yandexEnabled): ?>
            <a class="btn btn-yandex btn-block" href="/auth/yandex"><span class="ya-logo" aria-hidden="true">Я</span> Регистрация через Яндекс ID</a>
            <div class="divider"><span>или по email</span></div>
        <?php endif; ?>
        <form method="post" action="/register" novalidate>
            <?= $this->csrfField() ?>
            <div class="field">
                <label for="name">Как вас зовут</label>
                <input id="name" type="text" name="name" value="<?= $this->old('name') ?>" required maxlength="80" autocomplete="name" class="<?= $this->hasError('name') ? 'has-error' : '' ?>">
                <?= $this->error('name') ?>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $this->old('email') ?>" required autocomplete="email" class="<?= $this->hasError('email') ? 'has-error' : '' ?>">
                <?= $this->error('email') ?>
            </div>
            <div class="field">
                <label for="password">Пароль</label>
                <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password" class="<?= $this->hasError('password') ? 'has-error' : '' ?>">
                <p class="hint">Не короче 8 символов, буквы и цифры</p>
                <?= $this->error('password') ?>
            </div>
            <div class="field">
                <label for="password_confirmation">Повторите пароль</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="<?= $this->hasError('password_confirmation') ? 'has-error' : '' ?>">
                <?= $this->error('password_confirmation') ?>
            </div>
            <label class="check"><input type="checkbox" name="terms" value="1" required> Принимаю <a href="/terms" target="_blank">пользовательское соглашение</a> и <a href="/privacy" target="_blank">политику конфиденциальности</a></label>
            <?= $this->error('terms') ?>
            <label class="check"><input type="checkbox" name="digest" value="1" checked> Присылать недельный дайджест лучших материалов</label>
            <label class="check"><input type="checkbox" name="marketing" value="1"> Согласен получать новости и специальные предложения</label>
            <button type="submit" class="btn btn-primary btn-block">Создать аккаунт</button>
        </form>
        <p class="auth-switch">Уже есть аккаунт? <a href="/login">Войти</a></p>
    </div>
</div>
