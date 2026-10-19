<?php
/** @var \App\Core\View\View $this */
/** @var array<string,mixed>|null $heading */
/** @var list<\App\Article\Article> $featured */
/** @var list<\App\Article\Article> $items */
/** @var ?string $next */
/** @var ?string $activeCategory */
/** @var list<string> $activeTags */
/** @var list<\App\Article\Article> $popular */
/** @var list<\App\Taxonomy\Tag> $popularTags */
$this->layout('layouts/web');
$excludeIds = implode(',', array_map(static fn ($a): int => $a->id, $featured));
?>
<div class="container">
    <?php if ($heading): ?>
        <header class="page-heading" id="page-heading"<?= $heading['color'] ? ' style="--accent-line: ' . $this->e($heading['color']) . '"' : '' ?>>
            <h1><?= $this->e($heading['title']) ?></h1>
            <?php if ($heading['text'] !== ''): ?><p class="muted"><?= $this->e($heading['text']) ?></p><?php endif; ?>
        </header>
    <?php endif; ?>

    <?php if ($featured !== []): ?>
        <section class="hero" aria-label="Главное">
            <div class="hero-main"><?= $this->partial('partials/article-card', ['article' => $featured[0], 'variant' => 'featured']) ?></div>
            <?php if (count($featured) > 1): ?>
                <div class="hero-side">
                    <?php foreach (array_slice($featured, 1) as $article): ?><?= $this->partial('partials/article-card', ['article' => $article, 'variant' => 'default']) ?><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="layout-sidebar">
        <section aria-label="Лента материалов">
            <div class="filters" id="feed-filters" data-base="/">
                <div class="filter-row" role="group" aria-label="Рубрики">
                    <a href="/" class="chip-filter<?= $activeCategory === null ? ' is-active' : '' ?>" data-category="">Все</a>
                    <?php foreach ($categories as $category): ?>
                        <a href="/?category=<?= $this->e($category->slug) ?>" class="chip-filter<?= $activeCategory === $category->slug ? ' is-active' : '' ?>" data-category="<?= $this->e($category->slug) ?>"><?= $this->e($category->name) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if ($popularTags !== []): ?>
                    <div class="filter-row" role="group" aria-label="Темы">
                        <?php foreach ($popularTags as $tag): ?>
                            <a href="/?tags=<?= $this->e($tag->slug) ?>" class="chip-filter chip-tag<?= in_array($tag->slug, $activeTags, true) ? ' is-active' : '' ?>" data-tag="<?= $this->e($tag->slug) ?>">#<?= $this->e($tag->name) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="feed-grid" id="feed" data-endpoint="/api/feed" data-next="<?= $this->e((string) $next) ?>" data-exclude="<?= $this->e($excludeIds) ?>"
                 data-category="<?= $this->e((string) $activeCategory) ?>" data-tags="<?= $this->e(implode(',', $activeTags)) ?>" aria-live="polite">
                <?php foreach ($items as $article): ?><?= $this->partial('partials/article-card', ['article' => $article, 'variant' => 'default']) ?><?php endforeach; ?>
            </div>
            <p class="empty-state" id="feed-empty" <?= $items === [] ? '' : 'hidden' ?>>По этому фильтру пока нет материалов. <a href="/">Сбросить</a></p>
            <div class="feed-more" id="feed-more" <?= $next ? '' : 'hidden' ?>>
                <a class="btn btn-ghost" id="feed-more-btn" rel="next" href="<?= $this->e(($baseUrl ?? '/') . '?cursor=' . $next) ?>">Показать ещё</a>
            </div>
            <p class="feed-end muted" id="feed-end" hidden>Вы дочитали до конца ленты</p>
        </section>

        <aside class="sidebar" aria-label="Дополнительно">
            <?php if ($popular !== []): ?>
                <section class="side-block">
                    <h2 class="side-title">Популярное</h2>
                    <ol class="popular-list">
                        <?php foreach ($popular as $i => $article): ?>
                            <li><span class="popular-num"><?= $i + 1 ?></span><div><?= $this->partial('partials/article-card', ['article' => $article, 'variant' => 'compact']) ?></div></li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
            <?php if (!$user): ?>
                <section class="side-block side-promo">
                    <h2 class="side-title">Читайте без ограничений</h2>
                    <p>Оформите подписку и откройте все материалы. Первые несколько статей в месяц — бесплатно.</p>
                    <a class="btn btn-primary btn-block" href="/subscribe">Выбрать подписку</a>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/feed.js') ?>" defer></script>
<?php $this->end(); ?>
