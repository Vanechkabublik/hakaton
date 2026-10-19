<?php

declare(strict_types=1);

namespace App\Comment;

use App\Article\Article;
use App\Article\ArticleRepository;
use App\Audit\AuditLog;
use App\Core\Http\ValidationException;
use App\Core\Queue\Queue;
use App\Notification\ReplyNotificationJob;
use App\Settings\SettingsService;
use App\Support\TextFormatter;
use App\User\Gate;
use App\User\User;

/** Публикация, правка, лайки, жалобы и модерация комментариев. */
final class CommentService
{
    private const REPORTS_TO_HIDE = 3;
    private const MIN_LENGTH = 2;

    public function __construct(
        private readonly CommentRepository $comments,
        private readonly ArticleRepository $articles,
        private readonly SettingsService $settings,
        private readonly Queue $queue,
        private readonly AuditLog $audit,
        private readonly Gate $gate,
    ) {
    }

    /**
     * @throws ValidationException
     * @throws \DomainException
     */
    public function post(User $user, Article $article, string $body, ?int $parentId, ?string $ip): Comment
    {
        $this->assertCanComment($user);
        if (!$article->allowComments || !$article->isPublished()) {
            throw new \DomainException('Комментарии к этому материалу закрыты');
        }
        $body = $this->normalize($body);

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->comments->find($parentId);
            if ($parent === null || $parent->articleId !== $article->id || !$parent->isApproved()) {
                throw new \DomainException('Комментарий, на который вы отвечаете, недоступен');
            }
        }
        if ($this->comments->recentDuplicate($user->id, $article->id, $body)) {
            throw new ValidationException(['body' => 'Такой комментарий уже опубликован']);
        }

        $id = $this->comments->create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'parent_id' => CommentTree::clampParent($parent, $this->comments),
            'body' => $body,
            'status' => $this->initialStatus($user, $body),
            'ip' => $ip,
        ]);
        $comment = $this->comments->find($id) ?? throw new \RuntimeException('Комментарий не создан');
        $this->articles->refreshCommentsCount($article->id);
        if ($comment->isApproved()) {
            $this->notifyReply($comment);
        }

        return $comment;
    }

    /**
     * Правка автором в течение 5 минут.
     *
     * @throws ValidationException
     * @throws \DomainException
     */
    public function edit(User $user, Comment $comment, string $body): Comment
    {
        if (!$comment->canBeEditedBy($user->id)) {
            throw new \DomainException('Комментарий можно редактировать только в течение 5 минут после публикации');
        }
        $body = $this->normalize($body);
        $changes = ['body' => $body, 'edited_at' => new \DateTimeImmutable()];
        // Если в правке появились ссылки — уходит на повторную проверку
        if ($comment->isApproved() && TextFormatter::containsLink($body) && !TextFormatter::containsLink($comment->body) && !$this->gate->allows($user, 'comments.moderate')) {
            $changes['status'] = 'pending';
        }
        $this->comments->update($comment->id, $changes);
        $this->articles->refreshCommentsCount($comment->articleId);

        return $this->comments->find($comment->id) ?? $comment;
    }

    /** @return array{liked:bool,count:int} */
    public function toggleLike(User $user, Comment $comment): array
    {
        $this->assertCanComment($user);
        if (!$comment->isApproved()) {
            throw new \DomainException('Комментарий недоступен');
        }
        if ($comment->userId === $user->id) {
            throw new \DomainException('Нельзя оценивать собственный комментарий');
        }
        $liked = $this->comments->toggleLike($comment->id, $user->id);

        return ['liked' => $liked, 'count' => $this->comments->find($comment->id)?->likesCount ?? 0];
    }

    /**
     * @throws \DomainException
     */
    public function report(User $user, Comment $comment, string $reason, string $details): bool
    {
        if (!in_array($reason, ['spam', 'abuse', 'offtopic', 'illegal', 'other'], true)) {
            $reason = 'other';
        }
        if ($comment->userId === $user->id) {
            throw new \DomainException('Нельзя пожаловаться на собственный комментарий');
        }
        $added = $this->comments->addReport($comment->id, $user->id, $reason, $details);
        $fresh = $this->comments->find($comment->id);
        if ($added && $fresh !== null && $fresh->isApproved() && $fresh->reportsCount >= self::REPORTS_TO_HIDE) {
            // Автоскрытие до решения модератора
            $this->comments->update($comment->id, ['status' => 'pending']);
            $this->articles->refreshCommentsCount($comment->articleId);
        }

        return $added;
    }

    /** Модерация: одобрить / отклонить / спам. */
    public function moderate(Comment $comment, string $status, User $moderator): void
    {
        if (!in_array($status, ['approved', 'rejected', 'spam', 'pending'], true)) {
            throw new \InvalidArgumentException('Неизвестный статус');
        }
        $wasApproved = $comment->isApproved();
        $this->comments->update($comment->id, [
            'status' => $status,
            'moderated_by' => $moderator->id,
            'moderated_at' => new \DateTimeImmutable(),
        ]);
        if ($status === 'approved' || $status === 'rejected' || $status === 'spam') {
            $this->comments->resolveReports($comment->id);
        }
        $this->articles->refreshCommentsCount($comment->articleId);
        $this->audit->record('comment.' . $status, $moderator->id, 'comment', $comment->id);
        if ($status === 'approved' && !$wasApproved) {
            $this->notifyReply($comment);
        }
    }

    /**
     * Массовая модерация.
     *
     * @param list<int> $ids
     */
    public function bulkModerate(array $ids, string $status, User $moderator): int
    {
        if (!in_array($status, ['approved', 'rejected', 'spam', 'pending'], true)) {
            return 0;
        }
        $articleIds = $this->comments->articleIdsFor($ids);
        $count = $this->comments->bulkSetStatus($ids, $status, $moderator->id);
        foreach ($articleIds as $articleId) {
            $this->articles->refreshCommentsCount($articleId);
        }
        $this->audit->record('comment.bulk_' . $status, $moderator->id, 'comment', null, ['count' => $count]);

        return $count;
    }

    private function initialStatus(User $user, string $body): string
    {
        if ($this->settings->bool('comments.premoderation')) {
            return 'pending';
        }
        // Первый комментарий пользователя со ссылкой, либо много ссылок → на проверку
        if (TextFormatter::linkCount($body) > 2) {
            return 'pending';
        }
        if (TextFormatter::containsLink($body) && $this->comments->countApprovedByUser($user->id) < 3 && !$this->gate->allows($user, 'comments.moderate')) {
            return 'pending';
        }

        return 'approved';
    }

    private function notifyReply(Comment $comment): void
    {
        if ($comment->parentId !== null) {
            $this->queue->push(ReplyNotificationJob::class, ['comment_id' => $comment->id]);
        }
    }

    /** @throws \DomainException */
    private function assertCanComment(User $user): void
    {
        if (!$user->isActive()) {
            throw new \DomainException('Ваш аккаунт заблокирован');
        }
        if (!$user->isEmailVerified()) {
            throw new \DomainException('Подтвердите email, чтобы оставлять комментарии');
        }
    }

    /** @throws ValidationException */
    private function normalize(string $body): string
    {
        $body = trim(preg_replace("/\r\n?/", "\n", $body) ?? '');
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        $max = max(100, $this->settings->int('comments.max_length', 3000));
        $length = mb_strlen($body);
        if ($length < self::MIN_LENGTH) {
            throw new ValidationException(['body' => 'Комментарий слишком короткий']);
        }
        if ($length > $max) {
            throw new ValidationException(['body' => "Комментарий длиннее {$max} символов"]);
        }

        return $body;
    }
}
