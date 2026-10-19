<?php
/** @var \App\Core\View\View $this */
/** @var string $section */
$this->layout('layouts/web', ['bodyClass' => 'page-account']);
$items = [
    'overview' => ['/account', 'Обзор'],
    'profile' => ['/account/profile', 'Профиль'],
    'subscription' => ['/account/subscription', 'Подписка'],
    'payments' => ['/account/payments', 'Платежи и чеки'],
    'bookmarks' => ['/account/bookmarks', 'Закладки'],
    'history' => ['/account/history', 'История чтения'],
    'comments' => ['/account/comments', 'Мои комментарии'],
    'notifications' => ['/account/notifications', 'Уведомления'],
    'security' => ['/account/two-factor', 'Безопасность'],
];
?>
<div class="container account-layout">
    <aside class="account-nav" aria-label="Разделы кабинета">
        <div class="account-user">
            <?php if ($user->avatarUrl()): ?><img class="avatar avatar-lg" src="<?= $this->e($user->avatarUrl()) ?>" alt="" width="56" height="56"><?php else: ?><span class="avatar avatar-lg avatar-text"><?= $this->e($user->initials()) ?></span><?php endif; ?>
            <div><strong><?= $this->e($user->name) ?></strong><span class="muted small"><?= $this->e($user->email) ?></span></div>
        </div>
        <nav>
            <ul>
                <?php foreach ($items as $key => [$href, $label]): ?>
                    <li><a href="<?= $href ?>" class="<?= ($section ?? '') === $key ? 'is-active' : '' ?>"<?= ($section ?? '') === $key ? ' aria-current="page"' : '' ?>><?= $this->e($label) ?></a></li>
                <?php endforeach; ?>
                <?php if ($gate->allows($user, 'admin.access')): ?><li><a href="/admin">Админ-панель →</a></li><?php endif; ?>
            </ul>
        </nav>
    </aside>
    <section class="account-content">
        <?= $this->section('content') ?>
    </section>
</div>
