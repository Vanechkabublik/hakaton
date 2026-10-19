<?php
/** @var \App\Core\View\View $this */
/** @var string $email */
$this->layout('layouts/web');
?>
<div class="container auth-wrap">
    <div class="auth-card">
        <h1>Подтвердите email</h1>
        <p>Мы отправили письмо<?= $email !== '' ? ' на <strong>' . $this->e($email) . '</strong>' : '' ?>. Перейдите по ссылке в письме, чтобы завершить регистрацию. Ссылка действует 48 часов.</p>
        <p class="muted small">Не пришло? Проверьте папку «Спам» или запросите письмо ещё раз.</p>
        <form method="post" action="/verify-email/resend">
            <?= $this->csrfField() ?>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?= $this->e($email) ?>" required autocomplete="email">
            </div>
            <button type="submit" class="btn btn-ghost btn-block">Отправить письмо ещё раз</button>
        </form>
        <p class="auth-switch"><a href="/login">Вернуться ко входу</a></p>
    </div>
</div>
