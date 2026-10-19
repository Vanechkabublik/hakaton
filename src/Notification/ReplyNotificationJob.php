<?php

declare(strict_types=1);

namespace App\Notification;

use App\Comment\CommentRepository;
use App\Core\Config;
use App\Core\Queue\Job;
use App\Core\Queue\Queue;
use App\User\UserRepository;

/** Уведомление автору комментария об ответе на него. */
final class ReplyNotificationJob implements Job
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly Queue $queue,
        private readonly Config $config,
    ) {
    }

    public function handle(array $payload): void
    {
        $reply = $this->comments->find((int) $payload['comment_id']);
        if ($reply === null || !$reply->isApproved() || $reply->parentId === null) {
            return;
        }
        $parent = $this->comments->find($reply->parentId);
        if ($parent === null || $parent->userId === null || $parent->userId === $reply->userId) {
            return;
        }
        $recipient = $this->users->find($parent->userId);
        if ($recipient === null || !$recipient->isActive() || !$recipient->isEmailVerified()) {
            return;
        }
        $url = $this->config->appUrl() . '/news/' . $reply->articleSlug . '#comment-' . $reply->id;
        if ($recipient->notifyReplies) {
            $this->mail->queue($recipient->email, $reply->authorDisplayName() . ' ответил на ваш комментарий', 'comment-reply', [
                'name' => $recipient->firstName(),
                'author' => $reply->authorDisplayName(),
                'article' => $reply->articleTitle,
                'excerpt' => mb_strimwidth($reply->body, 0, 300, '…'),
                'url' => $url,
            ], $recipient, true);
        }
        if ($recipient->notifyPush) {
            $this->queue->push(SendPushJob::class, [
                'user_id' => $recipient->id,
                'title' => $reply->authorDisplayName() . ' ответил вам',
                'body' => mb_strimwidth($reply->body, 0, 120, '…'),
                'url' => $url,
                'tag' => 'reply-' . $parent->id,
            ]);
        }
    }
}
