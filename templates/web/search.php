<?php
/** @var \App\Core\View\View $this */
/** @var string $query */
/** @var list<\App\Article\Article> $items */
/** @var int $total */
$this->layout('layouts/web');
?>
<div class="container container-narrow">
    <h1 class="page-title">Поиск</h1>
    <form action="/search" method="get" class="search-page-form">
        <label class="visually-hidden" for="q">Запрос</label>
        <input id="q" type="search" name="q" value="<?= $this->e($query) ?>" placeholder="Что ищете?" maxlength="120" autofocus>
        <button type="submit" class="btn btn-primary">Найти</button>
    </form>

    <?php if ($query === ''): ?>
        <p class="muted">Введите запрос — ищем по заголовкам, анонсам и тексту материалов.</p>
        <?php if ($popularTags !== []): ?>
            <p class="muted small">Популярные темы:</p>
            <ul class="tag-list"><?php foreach ($popularTags as $tag): ?><li><a class="chip-filter chip-tag" href="/tag/<?= $this->e($tag->slug) ?>">#<?= $this->e($tag->name) ?></a></li><?php endforeach; ?></ul>
        <?php endif; ?>
    <?php elseif (mb_strlen($query) < 2): ?>
        <p class="muted">Введите минимум два символа.</p>
    <?php elseif ($total === 0): ?>
        <p class="empty-state">По запросу «<?= $this->e($query) ?>» ничего не найдено. Попробуйте другие слова.</p>
    <?php else: ?>
        <p class="muted">Найдено: <?= $this->number($total) ?> <?= $this->plural($total, ['материал', 'материала', 'материалов']) ?></p>
        <ol class="search-results">
            <?php foreach ($items as $article): ?>
                <li class="search-item">
                    <div class="card-kicker">
                        <?php if ($article->categoryName): ?><span class="chip" style="--chip: <?= $this->e((string) $article->categoryColor) ?>"><?= $this->e($article->categoryName) ?></span><?php endif; ?>
                        <?php if ($article->isPaid()): ?><span class="lock-badge">Подписка</span><?php endif; ?>
                        <time class="muted small" datetime="<?= $this->e($this->iso($article->displayDate())) ?>"><?= $this->e($this->date($article->displayDate())) ?></time>
                    </div>
                    <h2 class="search-title"><a href="<?= $this->e($article->url()) ?>"><?= $this->e($article->title) ?></a></h2>
                    <?php if ($article->headline): ?><p class="search-snippet"><?= $article->headline ?></p><?php else: ?><p class="search-snippet"><?= $this->e($article->excerpt(200)) ?></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/search', 'query' => ['q' => $query]]) ?>
    <?php endif; ?>
</div>
