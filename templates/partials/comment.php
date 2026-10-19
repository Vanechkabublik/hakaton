<?php
/** @var \App\Core\View\View $this */
/** @var \App\Comment\Comment $comment */
/** @var \App\User\User|null $viewer */
$viewer = $viewer ?? null;
$withChildren = $withChildren ?? true;
$canEdit = $viewer !== null && $comment->canBeEditedBy($viewer->id);
$isOwn = $viewer !== null && $comment->userId === $viewer->id;
$isStaffAuthor = in_array($comment->authorRole, ['author', 'editor', 'admin'], true);
$maxDepth = \App\Comment\CommentTree::MAX_DEPTH;
?>
<li class="comment<?= $comment->status === 'pending' ? ' is-pending' : '' ?>" id="comment-<?= (int) $comment->id ?>" data-id="<?= (int) $comment->id ?>" data-depth="<?= (int) $comment->depth ?>">
    <div class="comment-main">
        <div class="comment-avatar">
            <?php if ($comment->authorAvatar): ?>
                <img class="avatar" src="<?= $this->e(str_starts_with($comment->authorAvatar, 'http') ? $comment->authorAvatar : '/uploads/' . $comment->authorAvatar) ?>" alt="" width="36" height="36" loading="lazy">
            <?php else: ?>
                <span class="avatar avatar-text"><?= $this->e(mb_strtoupper(mb_substr($comment->authorDisplayName(), 0, 1))) ?></span>
            <?php endif; ?>
        </div>
        <div class="comment-content">
            <div class="comment-head">
                <strong class="comment-author"><?= $this->e($comment->authorDisplayName()) ?></strong>
                <?php if ($isStaffAuthor): ?><span class="type-badge">Редакция</span><?php endif; ?>
                <a class="comment-time" href="#comment-<?= (int) $comment->id ?>"><time datetime="<?= $this->e($this->iso($comment->createdAt)) ?>"><?= $this->e($this->ago($comment->createdAt)) ?></time></a>
                <?php if ($comment->editedAt): ?><span class="muted small" title="<?= $this->e($this->dateTime($comment->editedAt)) ?>">изменён</span><?php endif; ?>
                <?php if ($comment->status === 'pending'): ?><span class="pending-badge">на модерации</span><?php endif; ?>
            </div>
            <div class="comment-body" data-comment-body data-raw="<?= $this->e($comment->body) ?>"><?= \App\Support\TextFormatter::commentHtml($comment->body) ?></div>
            <div class="comment-actions">
                <?php if ($comment->isApproved()): ?>
                    <button type="button" class="link-btn like-btn<?= $comment->likedByMe ? ' is-active' : '' ?>" data-action="like" aria-pressed="<?= $comment->likedByMe ? 'true' : 'false' ?>" <?= $isOwn ? 'disabled' : '' ?>>
                        <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.5-9.2C1.2 8.6 3 5 6.4 5c2 0 3.4 1.1 4.1 2.3h3C14.2 6.1 15.6 5 17.6 5 21 5 22.8 8.6 21.5 11.8 19.500 16.400 12 21 12 21Z" fill="currentColor"/></svg>
                        <span data-likes><?= (int) $comment->likesCount ?></span>
                    </button>
                    <?php if ($viewer !== null && $comment->depth < $maxDepth): ?><button type="button" class="link-btn" data-action="reply">Ответить</button><?php endif; ?>
                <?php endif; ?>
                <?php if ($canEdit): ?><button type="button" class="link-btn" data-action="edit" data-until="<?= $this->e($this->iso($comment->editableUntil())) ?>">Изменить</button><?php endif; ?>
                <?php if ($viewer !== null && !$isOwn && $comment->isApproved()): ?><button type="button" class="link-btn muted" data-action="report">Пожаловаться</button><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($withChildren && $comment->children !== []): ?>
        <ul class="comment-children">
            <?php foreach ($comment->children as $child): ?>
                <?= $this->partial('partials/comment', ['comment' => $child, 'viewer' => $viewer, 'withChildren' => true]) ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</li>
