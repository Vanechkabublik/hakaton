<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Article\Article> $items */
$this->layout('layouts/account');
?>
<h1>Закладки</h1>
<?php if ($items === []): ?>
    <p class="empty-state">Здесь будут статьи, которые вы сохранили кнопкой «Сохранить». <a href="/">Перейти к ленте</a></p>
<?php else: ?>
    <p class="muted">Сохранено материалов: <?= (int) $total ?></p>
    <div class="feed-grid"><?php foreach ($items as $article): ?><?= $this->partial('partials/article-card', ['article' => $article, 'variant' => 'default']) ?><?php endforeach; ?></div>
    <?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/account/bookmarks']) ?>
<?php endif; ?>
