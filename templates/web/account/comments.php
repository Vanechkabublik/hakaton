<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Comment\Comment> $items */
$this->layout('layouts/account');
?>
<h1>Мои комментарии</h1>
<?php if ($items === []): ?>
    <p class="empty-state">Вы ещё не оставляли комментариев.</p>
<?php else: ?>
    <ul class="my-comments">
        <?php foreach ($items as $comment): ?>
            <li>
                <a href="/news/<?= $this->e((string) $comment->articleSlug) ?>#comment-<?= (int) $comment->id ?>"><?= $this->e((string) $comment->articleTitle) ?></a>
                <div class="comment-body"><?= \App\Support\TextFormatter::commentHtml($comment->body) ?></div>
                <span class="muted small"><?= $this->e($this->ago($comment->createdAt)) ?> · ♥ <?= (int) $comment->likesCount ?> ·
                    <?= $this->e(['approved' => 'опубликован', 'pending' => 'на модерации', 'rejected' => 'отклонён', 'spam' => 'отмечен как спам'][$comment->status] ?? $comment->status) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/account/comments']) ?>
<?php endif; ?>
