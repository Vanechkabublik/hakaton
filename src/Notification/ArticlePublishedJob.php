<?php

declare(strict_types=1);

namespace App\Notification;

use App\Article\ArticleRepository;
use App\Core\Config;
use App\Core\Queue\Job;
use App\Core\Queue\Queue;
use App\Engagement\AuthorFollowRepository;
use App\User\UserRepository;

/** После публикации сообщает подписчикам автора (email и push). */
final class ArticlePublishedJob implements Job
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly AuthorFollowRepository $follows,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly Queue $queue,
        private readonly Config $config,
    ) {
    }

    public function handle(array $payload): void
    {
        $article = $this->articles->find((int) $payload['article_id']);
        if ($article === null || !$article->isPublished() || $article->authorId === null) {
            return;
        }
        $url = $this->config->appUrl() . $article->url();
        foreach ($this->follows->followerIdsToNotify($article->authorId) as $userId) {
            $user = $this->users->find($userId);
            if ($user === null) {
                continue;
            }
            if ($user->notifyAuthors) {
                $this->mail->queue($user->email, 'Новый материал: ' . $article->title, 'new-article', [
                    'name' => $user->firstName(),
                    'author' => $article->authorName,
                    'title' => $article->title,
                    'lead' => $article->excerpt(240),
                    'url' => $url . '?utm_source=email&utm_medium=email&utm_campaign=author',
                    'cover' => $article->hasCover() ? $this->config->appUrl() . $article->coverUrl(640) : null,
                ], $user, true);
            }
            if ($user->notifyPush) {
                $this->queue->push(SendPushJob::class, [
                    'user_id' => $user->id,
                    'title' => ($article->authorName ?? 'Новая статья') . ' опубликовал(а) материал',
                    'body' => $article->title,
                    'url' => $url,
                    'tag' => 'article-' . $article->id,
                ]);
            }
        }
    }
}
