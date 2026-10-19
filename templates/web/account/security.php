<?php
/** @var \App\Core\View\View $this */
/** @var ?array{secret:string,uri:string,qr:string} $setup */
/** @var ?list<string> $recoveryCodes */
/** @var int $recoveryLeft */
/** @var bool $required */
/** @var list<array<string,mixed>> $sessions */
$this->layout('layouts/account');
?>
<h1>Безопасность</h1>

<?php if ($recoveryCodes): ?>
    <section class="panel panel-warning" aria-labelledby="rc-title">
        <h2 class="panel-title" id="rc-title">Коды восстановления</h2>
        <p>Сохраните эти коды в надёжном месте. Каждый можно использовать один раз, если потеряете доступ к приложению. <strong>Больше они показаны не будут.</strong></p>
        <ul class="recovery-codes"><?php foreach ($recoveryCodes as $code): ?><li><code><?= $this->e($code) ?></code></li><?php endforeach; ?></ul>
    </section>
<?php endif; ?>

<section class="panel">
    <h2 class="panel-title">Двухфакторная аутентификация (TOTP)</h2>
    <?php if ($user->hasTwoFactor()): ?>
        <p><span class="badge badge-success">Включена</span> с <?= $this->e($this->date($user->totpEnabledAt)) ?>. Осталось кодов восстановления: <strong><?= (int) $recoveryLeft ?></strong>.</p>
        <details class="cancel-box">
            <summary>Новые коды восстановления</summary>
            <form method="post" action="/account/two-factor/recovery" class="card-form">
                <?= $this->csrfField() ?>
                <?php if ($user->hasPassword()): ?><div class="field"><label for="rp">Пароль</label><input id="rp" type="password" name="password" required autocomplete="current-password"><?= $this->error('password') ?></div><?php endif; ?>
                <button type="submit" class="btn btn-ghost">Сгенерировать новые коды</button>
            </form>
        </details>
        <?php if ($required): ?>
            <p class="muted small">Для вашей роли (<?= $this->e($user->role->label()) ?>) двухфакторная аутентификация обязательна. Если нужно сбросить её, обратитесь к администратору.</p>
        <?php else: ?>
            <details class="cancel-box">
                <summary>Отключить 2FA</summary>
                <form method="post" action="/account/two-factor/disable" class="card-form" data-confirm="Отключить двухфакторную аутентификацию?">
                    <?= $this->csrfField() ?>
                    <?php if ($user->hasPassword()): ?><div class="field"><label for="dp">Пароль</label><input id="dp" type="password" name="password" required autocomplete="current-password"><?= $this->error('password') ?></div><?php endif; ?>
                    <button type="submit" class="btn btn-danger">Отключить</button>
                </form>
            </details>
        <?php endif; ?>
    <?php elseif ($setup): ?>
        <?php if ($required): ?><div class="notice notice-warning">Для доступа в админ-панель необходимо включить двухфакторную аутентификацию.</div><?php endif; ?>
        <ol class="steps">
            <li>Установите приложение-аутентификатор (Яндекс Ключ, Google Authenticator, Authy, 1Password).</li>
            <li>Отсканируйте QR-код или введите секрет вручную:
                <div class="qr-box"><div class="qr" aria-label="QR-код для аутентификатора"><?= $setup['qr'] ?></div>
                    <div><p class="muted small">Секрет для ручного ввода:</p><code class="secret"><?= $this->e(implode(' ', str_split($setup['secret'], 4))) ?></code></div></div>
            </li>
            <li>Введите 6-значный код из приложения:
                <form method="post" action="/account/two-factor/enable" class="card-form" novalidate>
                    <?= $this->csrfField() ?>
                    <div class="field">
                        <label class="visually-hidden" for="code">Код</label>
                        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required class="code-input <?= $this->hasError('code') ? 'has-error' : '' ?>" placeholder="123456">
                        <?= $this->error('code') ?>
                    </div>
                    <button type="submit" class="btn btn-primary">Включить</button>
                </form>
            </li>
        </ol>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head"><h2 class="panel-title">Активные сеансы</h2>
        <form method="post" action="/account/sessions/logout-others" data-confirm="Выйти на всех остальных устройствах?"><?= $this->csrfField() ?><button type="submit" class="btn btn-ghost btn-sm">Выйти на других устройствах</button></form></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Устройство</th><th>IP</th><th>Активность</th></tr></thead>
        <tbody><?php foreach ($sessions as $s): ?>
            <tr><td><?= $this->e($this->truncate((string) $s['user_agent'], 70)) ?><?= $s['id'] === $currentHash ? ' <span class="badge badge-info">это устройство</span>' : '' ?></td>
                <td><?= $this->e((string) $s['ip']) ?></td><td><?= $this->e($this->ago((new \DateTimeImmutable('@' . (int) $s['last_activity'])))) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
