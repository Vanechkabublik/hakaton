<?php
/** @var \App\Core\View\View $this */
/** @var \App\User\User $author */
/** @var list<\App\Article\Article> $items */
/** @var ?string $next */
$this->layout('layouts/web');
?>
<div class="container">
    <header class="author-header">
        <?php if ($author->avatarUrl()): ?><img class="avatar avatar-xl" src="<?= $this->e($author->avatarUrl()) ?>" alt="" width="88" height="88"><?php else: ?><span class="avatar avatar-xl avatar-text"><?= $this->e($author->initials()) ?></span><?php endif; ?>
        <div>
            <h1><?= $this->e($author->name) ?></h1>
            <?php if ($author->bio !== ''): ?><p class="muted"><?= $this->e($author->bio) ?></p><?php endif; ?>
            <p class="small muted"><span id="followers-count"><?= (int) $followers ?></span> <?= $this->plural($followers, ['подписчик', 'подписчика', 'подписчиков']) ?></p>
        </div>
        <?php if ($user && $user->id !== $author->id): ?>
            <button type="button" class="btn btn-primary follow-btn<?= $following ? ' is-active' : '' ?>" data-author-id="<?= (int) $author->id ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>"><?= $following ? 'Вы подписаны' : 'Подписаться' ?></button>
        <?php elseif (!$user): ?>
            <a class="btn btn-primary" href="/login">Подписаться</a>
        <?php endif; ?>
    </header>

    <div class="feed-grid" id="feed" data-endpoint="/api/feed" data-next="<?= $this->e((string) $next) ?>" data-author="<?= (int) $author->id ?>" data-fixed="1">
        <?php foreach ($items as $article): ?><?= $this->partial('partials/article-card', ['article' => $article, 'variant' => 'default']) ?><?php endforeach; ?>
    </div>
    <?php if ($items === []): ?><p class="empty-state">У автора пока нет опубликованных материалов.</p><?php endif; ?>
    <div class="feed-more" id="feed-more" <?= $next ? '' : 'hidden' ?>>
        <a class="btn btn-ghost" id="feed-more-btn" rel="next" href="/author/<?= $this->e((string) $author->slug) ?>?cursor=<?= $this->e((string) $next) ?>">Показать ещё</a>
    </div>
    <p class="feed-end muted" id="feed-end" hidden>Это все материалы автора</p>
</div>
<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/feed.js') ?>" defer></script>
<script src="<?= $this->asset('js/article.js') ?>" defer></script>
<?php $this->end(); ?>
