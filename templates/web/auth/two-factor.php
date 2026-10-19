<?php
/** @var \App\Core\View\View $this */
$this->layout('layouts/web');
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1>Двухфакторная проверка</h1>
        <p class="muted">Введите 6-значный код из приложения-аутентификатора (Яндекс Ключ, Google Authenticator и др.) или один из кодов восстановления.</p>
        <form method="post" action="/login/two-factor" novalidate>
            <?= $this->csrfField() ?>
            <div class="field">
                <label for="code">Код</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required maxlength="20" class="code-input <?= $this->hasError('code') ? 'has-error' : '' ?>" placeholder="123456">
                <?= $this->error('code') ?>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Подтвердить</button>
        </form>
        <p class="auth-switch"><a href="/login">Вернуться ко входу</a></p>
    </div>
</div>
