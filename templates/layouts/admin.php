<?php
/** @var \App\Core\View\View $this */
/** @var \App\User\User $user */
/** @var \App\User\Gate $gate */
$section = $section ?? '';
$menu = [
    ['dashboard.view', '/admin', 'dashboard', 'Дашборд', '▦'],
    ['admin.access', '/admin/articles', 'articles', 'Статьи', '✎'],
    ['articles.publish', '/admin/articles?status=pending', 'moderation', 'Модерация статей', '✔'],
    ['taxonomy.manage', '/admin/categories', 'categories', 'Категории', '▤'],
    ['taxonomy.manage', '/admin/tags', 'tags', 'Теги', '#'],
    ['comments.moderate', '/admin/comments', 'comments', 'Комментарии', '✉'],
    ['media.upload', '/admin/media', 'media', 'Медиатека', '▣'],
    ['pages.manage', '/admin/pages', 'pages', 'Страницы', '☰'],
    ['users.manage', '/admin/users', 'users', 'Пользователи', '☺'],
    ['payments.manage', '/admin/payments', 'payments', 'Платежи', '₽'],
    ['subscriptions.manage', '/admin/subscriptions', 'subscriptions', 'Подписки', '★'],
    ['subscriptions.manage', '/admin/plans', 'plans', 'Тарифы', '◈'],
    ['subscriptions.manage', '/admin/promocodes', 'promocodes', 'Промокоды', '%'],
    ['newsletters.manage', '/admin/campaigns', 'campaigns', 'Рассылки', '➤'],
    ['settings.manage', '/admin/settings', 'settings', 'Настройки', '⚙'],
    ['security.manage', '/admin/security', 'security', 'Безопасность', '⛨'],
];
?>
<!doctype html>
<html lang="ru"<?= $theme !== 'auto' ? ' data-theme="' . $this->e($theme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($title ?? 'Админ-панель') ?> — <?= $this->e($site['name']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
    <link rel="icon" href="/assets/img/icon-192.png" type="image/png">
    <link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= $this->asset('css/admin.css') ?>">
    <script nonce="<?= $this->e($nonce) ?>">
        (function () { try { var t = localStorage.getItem('theme'); if ((t === 'dark' || t === 'light') && !document.documentElement.dataset.theme) { document.documentElement.dataset.theme = t; } } catch (e) {} })();
    </script>
    <?= $this->section('head') ?>
</head>
<body class="admin">
<a class="skip-link" href="#admin-main">Перейти к содержимому</a>
<div class="admin-shell">
    <aside class="admin-sidebar" id="admin-sidebar">
        <a class="brand" href="/admin"><span class="brand-mark" aria-hidden="true">V</span><span class="brand-name"><?= $this->e($site['name']) ?></span></a>
        <nav aria-label="Разделы админки">
            <ul>
                <?php foreach ($menu as [$ability, $href, $key, $label, $icon]): if (!$gate->allows($user, $ability)) { continue; } ?>
                    <li><a href="<?= $href ?>" class="<?= $section === $key ? 'is-active' : '' ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= $icon ?></span><?= $this->e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="admin-side-foot">
            <a href="/" target="_blank" rel="noopener">Открыть сайт ↗</a>
            <a href="/account">Мой аккаунт</a>
        </div>
    </aside>
    <div class="admin-body">
        <header class="admin-topbar">
            <button type="button" class="icon-btn" id="admin-menu-toggle" aria-label="Меню" aria-controls="admin-sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M3 6h18v2H3V6Zm0 5h18v2H3v-2Zm0 5h18v2H3v-2Z" fill="currentColor"/></svg></button>
            <h1 class="admin-title"><?= $this->e($title ?? 'Админ-панель') ?></h1>
            <div class="admin-top-actions">
                <?= $this->section('actions') ?>
                <button type="button" class="icon-btn" id="theme-toggle" aria-label="Переключить тему">
                    <svg class="i-sun" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10ZM11 1h2v3h-2V1Zm0 19h2v3h-2v-3ZM1 11h3v2H1v-2Zm19 0h3v2h-3v-2Z" fill="currentColor"/></svg>
                    <svg class="i-moon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" fill="currentColor"/></svg>
                </button>
                <span class="admin-user"><?= $this->e($user->name) ?> <span class="badge"><?= $this->e($user->role->label()) ?></span></span>
                <form action="/logout" method="post"><?= $this->csrfField() ?><button type="submit" class="btn btn-ghost btn-sm">Выйти</button></form>
            </div>
        </header>
        <div class="flash-stack" id="flash-stack">
            <?php foreach ($flash as $message): ?>
                <div class="flash flash-<?= $this->e($message['type']) ?>" role="status"><span><?= $this->e($message['message']) ?></span><button type="button" class="flash-close" aria-label="Закрыть">×</button></div>
            <?php endforeach; ?>
        </div>
        <main id="admin-main" class="admin-main">
            <?= $this->section('content') ?>
        </main>
    </div>
</div>
<div class="toast-region" id="toast-region" aria-live="polite"></div>
<script src="<?= $this->asset('js/core.js') ?>" defer></script>
<script src="<?= $this->asset('js/admin.js') ?>" defer></script>
<?= $this->section('scripts') ?>
</body>
</html>
