<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\User\User> $authors */
$this->layout('layouts/web');
?>
<div class="container">
    <h1 class="page-title">Авторы</h1>
    <?php if ($authors === []): ?><p class="muted">Авторы появятся после первых публикаций.</p><?php endif; ?>
    <div class="authors-grid">
        <?php foreach ($authors as $author): ?>
            <a class="author-card" href="/author/<?= $this->e((string) $author->slug) ?>">
                <?php if ($author->avatarUrl()): ?><img class="avatar avatar-lg" src="<?= $this->e($author->avatarUrl()) ?>" alt="" width="56" height="56" loading="lazy"><?php else: ?><span class="avatar avatar-lg avatar-text"><?= $this->e($author->initials()) ?></span><?php endif; ?>
                <strong><?= $this->e($author->name) ?></strong>
                <span class="muted small"><?= $this->e($this->truncate($author->bio, 90)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
