<?php
/** @var \App\Core\View\View $this */
/** @var \App\Seo\PageMeta $meta */
/** @var array<string,mixed> $site */
/** @var \App\User\User|null $user */
/** @var list<\App\Taxonomy\Category> $categories */
$nonce = $this->e($nonce);
$hasToken = $user !== null || $this->csrfResolved();
$bodyClass = $bodyClass ?? '';
?>
<!doctype html>
<html lang="ru"<?= $theme !== 'auto' ? ' data-theme="' . $this->e($theme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($meta->title) ?></title>
    <?php if ($meta->description !== ''): ?><meta name="description" content="<?= $this->e($this->truncate($meta->description, 300)) ?>"><?php endif; ?>

    <meta name="robots" content="<?= $this->e($meta->robots) ?>">
    <?php if ($meta->canonical !== ''): ?><link rel="canonical" href="<?= $this->e($meta->canonical) ?>"><?php endif; ?>

    <?php if ($meta->prevUrl): ?><link rel="prev" href="<?= $this->e($meta->prevUrl) ?>"><?php endif; ?>
    <?php if ($meta->nextUrl): ?><link rel="next" href="<?= $this->e($meta->nextUrl) ?>"><?php endif; ?>

    <meta property="og:site_name" content="<?= $this->e($site['name']) ?>">
    <meta property="og:type" content="<?= $this->e($meta->ogType) ?>">
    <meta property="og:title" content="<?= $this->e($meta->title) ?>">
    <meta property="og:description" content="<?= $this->e($this->truncate($meta->description, 200)) ?>">
    <meta property="og:url" content="<?= $this->e($meta->canonical) ?>">
    <meta property="og:locale" content="ru_RU">
    <?php if ($meta->image): ?><meta property="og:image" content="<?= $this->e($meta->image) ?>"><?php endif; ?>

    <?php if ($meta->publishedTime): ?><meta property="article:published_time" content="<?= $this->e($meta->publishedTime) ?>"><?php endif; ?>
    <?php if ($meta->modifiedTime): ?><meta property="article:modified_time" content="<?= $this->e($meta->modifiedTime) ?>"><?php endif; ?>
    <?php if ($meta->section): ?><meta property="article:section" content="<?= $this->e($meta->section) ?>"><?php endif; ?>
    <?php foreach ($meta->keywords as $keyword): ?><meta property="article:tag" content="<?= $this->e($keyword) ?>"><?php endforeach; ?>

    <meta name="twitter:card" content="<?= $meta->image ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= $this->e($meta->title) ?>">
    <meta name="twitter:description" content="<?= $this->e($this->truncate($meta->description, 200)) ?>">
    <?php if ($meta->image): ?><meta name="twitter:image" content="<?= $this->e($meta->image) ?>"><?php endif; ?>

    <?php if ($hasToken): ?><meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>"><?php endif; ?>

    <meta name="theme-color" content="#2563eb">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="alternate" type="application/rss+xml" title="<?= $this->e($site['name']) ?>" href="/rss.xml">
    <link rel="icon" href="/assets/img/icon-192.png" type="image/png">
    <link rel="apple-touch-icon" href="/assets/img/icon-192.png">
    <link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>">
    <script nonce="<?= $nonce ?>">
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if ((t === 'dark' || t === 'light') && !document.documentElement.dataset.theme) {
                    document.documentElement.dataset.theme = t;
                }
            } catch (e) {}
        })();
    </script>
    <?php foreach ($meta->jsonLd as $block): ?>
    <script type="application/ld+json" nonce="<?= $nonce ?>"><?= $this->json($block) ?></script>
    <?php endforeach; ?>
    <?= $this->section('head') ?>
</head>
<body class="<?= $this->e($bodyClass) ?>" data-vapid="<?= $this->e($site['vapid_public']) ?>" data-user="<?= $user ? '1' : '0' ?>">
<a class="skip-link" href="#main">Перейти к содержимому</a>

<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="/" aria-label="<?= $this->e($site['name']) ?> — на главную">
            <?php if ($site['logo']): ?>
                <img src="<?= $this->e($site['logo']) ?>" alt="<?= $this->e($site['name']) ?>" class="brand-logo" height="32">
            <?php else: ?>
                <span class="brand-mark" aria-hidden="true">V</span>
                <span class="brand-name"><?= $this->e($site['name']) ?></span>
            <?php endif; ?>
        </a>

        <form class="search" action="/search" method="get" role="search" autocomplete="off" data-suggest-url="/api/search/suggest">
            <label class="visually-hidden" for="site-search">Поиск по сайту</label>
            <input id="site-search" type="search" name="q" placeholder="Поиск по сайту" value="<?= $this->e($currentPath === '/search' ? ($query ?? '') : '') ?>"
                   role="combobox" aria-expanded="false" aria-controls="search-suggest" aria-autocomplete="list" maxlength="120">
            <button type="submit" class="icon-btn" aria-label="Найти"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 0 1 5.9 12.1l4.3 4.3-1.4 1.4-4.3-4.3A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z" fill="currentColor"/></svg></button>
            <div id="search-suggest" class="suggest" role="listbox" hidden></div>
        </form>

        <div class="header-actions">
            <button type="button" class="icon-btn" id="theme-toggle" aria-label="Переключить тему" title="Тема оформления">
                <svg class="i-sun" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10ZM11 1h2v3h-2V1Zm0 19h2v3h-2v-3ZM1 11h3v2H1v-2Zm19 0h3v2h-3v-2ZM4.2 5.6l1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm12.1 12.1 1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1ZM5.6 19.8l-1.4-1.4 2.1-2.1 1.4 1.4-2.1 2.1ZM17.7 7.7l-1.4-1.4 2.1-2.1 1.4 1.4-2.1 2.1Z" fill="currentColor"/></svg>
                <svg class="i-moon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" fill="currentColor"/></svg>
            </button>
            <?php if ($user): ?>
                <details class="user-menu">
                    <summary class="user-chip" aria-label="Меню аккаунта">
                        <?php if ($user->avatarUrl()): ?><img src="<?= $this->e($user->avatarUrl()) ?>" alt="" width="32" height="32" class="avatar"><?php else: ?><span class="avatar avatar-text"><?= $this->e($user->initials()) ?></span><?php endif; ?>
                    </summary>
                    <div class="menu-panel">
                        <div class="menu-head"><strong><?= $this->e($user->name) ?></strong><span><?= $this->e($user->email) ?></span></div>
                        <a href="/account">Личный кабинет</a>
                        <a href="/account/subscription">Моя подписка</a>
                        <a href="/account/bookmarks">Закладки</a>
                        <?php if ($gate->allows($user, 'admin.access')): ?><a href="/admin">Админ-панель</a><?php endif; ?>
                        <form action="/logout" method="post"><?= $this->csrfField() ?><button type="submit">Выйти</button></form>
                    </div>
                </details>
            <?php else: ?>
                <a class="btn btn-ghost" href="/login">Войти</a>
            <?php endif; ?>
            <a class="btn btn-primary btn-sm" href="/subscribe">Подписка</a>
        </div>
    </div>
    <nav class="main-nav" aria-label="Рубрики">
        <div class="container nav-row">
            <a href="/" class="<?= $currentPath === '/' ? 'is-active' : '' ?>">Главное</a>
            <?php foreach ($categories as $category): ?>
                <a href="/category/<?= $this->e($category->slug) ?>" class="<?= $currentPath === '/category/' . $category->slug ? 'is-active' : '' ?>"><?= $this->e($category->name) ?></a>
            <?php endforeach; ?>
            <a href="/type/longread" class="<?= $currentPath === '/type/longread' ? 'is-active' : '' ?>">Лонгриды</a>
            <a href="/authors" class="<?= $currentPath === '/authors' ? 'is-active' : '' ?>">Авторы</a>
        </div>
    </nav>
</header>

<div class="flash-stack" role="region" aria-label="Уведомления" id="flash-stack">
    <?php foreach ($flash as $message): ?>
        <div class="flash flash-<?= $this->e($message['type']) ?>" role="status"><span><?= $this->e($message['message']) ?></span><button type="button" class="flash-close" aria-label="Закрыть">×</button></div>
    <?php endforeach; ?>
</div>

<main id="main" class="site-main">
    <?= $this->section('content') ?>
</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <a class="brand brand-footer" href="/"><span class="brand-mark" aria-hidden="true">V</span><span class="brand-name"><?= $this->e($site['name']) ?></span></a>
            <p class="muted"><?= $this->e($site['tagline']) ?></p>
        </div>
        <nav aria-label="О проекте">
            <h2 class="footer-title">Проект</h2>
            <ul>
                <li><a href="/about">О проекте</a></li>
                <li><a href="/contacts">Контакты</a></li>
                <li><a href="/advertising">Реклама</a></li>
                <li><a href="/authors">Авторы</a></li>
            </ul>
        </nav>
        <nav aria-label="Правила">
            <h2 class="footer-title">Правила</h2>
            <ul>
                <li><a href="/comment-rules">Правила комментариев</a></li>
                <li><a href="/terms">Пользовательское соглашение</a></li>
                <li><a href="/privacy">Политика конфиденциальности</a></li>
            </ul>
        </nav>
        <nav aria-label="Подписка">
            <h2 class="footer-title">Читателям</h2>
            <ul>
                <li><a href="/subscribe">Подписка</a></li>
                <li><a href="/gift">Подарить подписку</a></li>
                <li><a href="/rss.xml">RSS-лента</a></li>
                <li><a href="/sitemap.xml">Карта сайта</a></li>
            </ul>
        </nav>
    </div>
    <div class="container footer-bottom">
        <span>© <?= date('Y') ?> <?= $this->e($site['name']) ?></span>
        <span class="muted">Платные материалы — по подписке</span>
    </div>
</footer>

<?php if ($cookieConsent === null): ?>
<div class="cookie-banner" id="cookie-banner" role="dialog" aria-labelledby="cookie-title" aria-live="polite">
    <div class="cookie-text">
        <strong id="cookie-title">Мы используем cookie</strong>
        <p>Необходимые cookie нужны для входа и работы сайта. Аналитические помогают понять, что читают чаще всего. Подробнее — в <a href="/privacy">политике конфиденциальности</a>.</p>
    </div>
    <div class="cookie-actions">
        <button type="button" class="btn btn-ghost" data-consent="necessary">Только необходимые</button>
        <button type="button" class="btn btn-primary" data-consent="all">Принять все</button>
    </div>
</div>
<?php endif; ?>

<div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="true"></div>

<script src="<?= $this->asset('js/core.js') ?>" defer></script>
<?= $this->section('scripts') ?>
<?php if ($cookieConsent === 'all'): ?>
    <?php if ($site['metrika_id'] !== ''): ?>
    <script nonce="<?= $nonce ?>">
        (function (m, e, t, r, i, k, a) {
            m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
            m[i].l = 1 * new Date();
            k = e.createElement(t), a = e.getElementsByTagName(t)[0], k.async = 1, k.src = r, a.parentNode.insertBefore(k, a);
        })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
        ym(<?= (int) $site['metrika_id'] ?>, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: false });
    </script>
    <?php endif; ?>
    <?php if ($site['ga4_id'] !== ''): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $this->e($site['ga4_id']) ?>" nonce="<?= $nonce ?>"></script>
    <script nonce="<?= $nonce ?>">
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', '<?= $this->e($site['ga4_id']) ?>', { anonymize_ip: true });
    </script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
