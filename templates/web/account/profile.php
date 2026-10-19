<?php
/** @var \App\Core\View\View $this */
/** @var bool $yandexEnabled */
/** @var ?array<string,mixed> $yandexIdentity */
$this->layout('layouts/account');
?>
<h1>Профиль</h1>

<section class="panel">
    <h2 class="panel-title">Основное</h2>
    <form method="post" action="/account/avatar" enctype="multipart/form-data" class="avatar-form">
        <?= $this->csrfField() ?>
        <?php if ($user->avatarUrl()): ?><img class="avatar avatar-xl" src="<?= $this->e($user->avatarUrl()) ?>" alt="Аватар" width="88" height="88"><?php else: ?><span class="avatar avatar-xl avatar-text"><?= $this->e($user->initials()) ?></span><?php endif; ?>
        <div>
            <label for="avatar" class="visually-hidden">Новый аватар</label>
            <input id="avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif">
            <?= $this->error('avatar') ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-ghost btn-sm">Загрузить</button>
                <?php if ($user->avatarUrl()): ?><button type="submit" name="remove" value="1" class="btn btn-ghost btn-sm">Удалить</button><?php endif; ?>
            </div>
            <p class="hint">JPG, PNG, WebP или GIF до 12 МБ. Изображение будет обрезано до квадрата.</p>
        </div>
    </form>
    <form method="post" action="/account/profile" class="card-form" novalidate>
        <?= $this->csrfField() ?>
        <div class="field">
            <label for="name">Имя</label>
            <input id="name" type="text" name="name" value="<?= $this->old('name', $user->name) ?>" required maxlength="80" class="<?= $this->hasError('name') ? 'has-error' : '' ?>">
            <?= $this->error('name') ?>
        </div>
        <?php if ($user->isStaff()): ?>
            <div class="field">
                <label for="bio">О себе (виден на странице автора)</label>
                <textarea id="bio" name="bio" rows="3" maxlength="500"><?= $this->old('bio', $user->bio) ?></textarea>
            </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Сохранить</button>
    </form>
</section>

<section class="panel">
    <h2 class="panel-title">Email</h2>
    <p>Текущий адрес: <strong><?= $this->e($user->email) ?></strong> <?= $user->isEmailVerified() ? '<span class="badge badge-success">подтверждён</span>' : '<span class="badge badge-warning">не подтверждён</span>' ?></p>
    <?php if ($user->pendingEmail): ?><p class="muted">Ожидает подтверждения: <?= $this->e($user->pendingEmail) ?></p><?php endif; ?>
    <form method="post" action="/account/email" class="card-form" novalidate>
        <?= $this->csrfField() ?>
        <div class="field">
            <label for="email">Новый email</label>
            <input id="email" type="email" name="email" required autocomplete="email" class="<?= $this->hasError('email') ? 'has-error' : '' ?>">
            <?= $this->error('email') ?>
            <p class="hint">Мы отправим письмо со ссылкой подтверждения на новый адрес.</p>
        </div>
        <button type="submit" class="btn btn-ghost">Сменить email</button>
    </form>
</section>

<section class="panel">
    <h2 class="panel-title"><?= $user->hasPassword() ? 'Смена пароля' : 'Задать пароль' ?></h2>
    <form method="post" action="/account/password" class="card-form" novalidate>
        <?= $this->csrfField() ?>
        <?php if ($user->hasPassword()): ?>
            <div class="field">
                <label for="current_password">Текущий пароль</label>
                <input id="current_password" type="password" name="current_password" required autocomplete="current-password" class="<?= $this->hasError('current_password') ? 'has-error' : '' ?>">
                <?= $this->error('current_password') ?>
            </div>
        <?php else: ?>
            <p class="muted">Вы входите через Яндекс ID. Задайте пароль, чтобы можно было входить и по email.</p>
        <?php endif; ?>
        <div class="field">
            <label for="new_password">Новый пароль</label>
            <input id="new_password" type="password" name="password" required minlength="8" autocomplete="new-password" class="<?= $this->hasError('password') ? 'has-error' : '' ?>">
            <?= $this->error('password') ?>
        </div>
        <div class="field">
            <label for="new_password2">Повторите пароль</label>
            <input id="new_password2" type="password" name="password_confirmation" required autocomplete="new-password">
            <?= $this->error('password_confirmation') ?>
        </div>
        <button type="submit" class="btn btn-ghost">Сохранить пароль</button>
    </form>
</section>

<section class="panel">
    <h2 class="panel-title">Яндекс ID</h2>
    <?php if ($yandexIdentity): ?>
        <p>Привязан аккаунт <strong><?= $this->e((string) ($yandexIdentity['login'] ?: $yandexIdentity['email'])) ?></strong>. Вход в один клик включён.</p>
        <form method="post" action="/account/yandex/unlink" data-confirm="Отвязать Яндекс ID? Войти через Яндекс будет нельзя.">
            <?= $this->csrfField() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Отвязать Яндекс ID</button>
            <?php if (!$user->hasPassword()): ?><p class="hint">Чтобы отвязать, сначала задайте пароль выше.</p><?php endif; ?>
        </form>
    <?php elseif ($yandexEnabled): ?>
        <p class="muted">Привяжите аккаунт Яндекса, чтобы входить без пароля.</p>
        <form method="post" action="/account/yandex/link"><?= $this->csrfField() ?><button type="submit" class="btn btn-yandex btn-sm"><span class="ya-logo" aria-hidden="true">Я</span> Привязать Яндекс ID</button></form>
    <?php else: ?>
        <p class="muted">Вход через Яндекс пока недоступен.</p>
    <?php endif; ?>
</section>

<section class="panel panel-danger">
    <h2 class="panel-title">Удаление аккаунта</h2>
    <?php if ($user->isStaff()): ?>
        <p class="muted">Сотрудники редакции не могут удалить аккаунт самостоятельно — обратитесь к администратору.</p>
    <?php else: ?>
        <p class="muted">Аккаунт, закладки, история чтения и сохранённый способ оплаты будут удалены безвозвратно. Данные о платежах хранятся обезличенно, как требует закон.</p>
        <form method="post" action="/account/delete" class="card-form" novalidate data-confirm="Удалить аккаунт без возможности восстановления?">
            <?= $this->csrfField() ?>
            <?php if ($user->hasPassword()): ?>
                <div class="field"><label for="del_password">Пароль</label><input id="del_password" type="password" name="password" required autocomplete="current-password"></div>
            <?php endif; ?>
            <div class="field">
                <label for="del_confirm">Введите слово «удалить»</label>
                <input id="del_confirm" type="text" name="confirm" required autocomplete="off" class="<?= $this->hasError('confirm') ? 'has-error' : '' ?>">
                <?= $this->error('confirm') ?>
            </div>
            <label class="check"><input type="checkbox" name="erase_comments" value="1"> Также заменить текст моих комментариев на «удалён автором»</label>
            <button type="submit" class="btn btn-danger">Удалить аккаунт</button>
        </form>
    <?php endif; ?>
</section>
