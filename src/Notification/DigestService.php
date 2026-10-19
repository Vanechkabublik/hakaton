<?php

declare(strict_types=1);

namespace App\Notification;

use App\Article\ArticleRepository;
use App\Core\Config;
use App\Core\Database\Database;
use App\Core\Queue\Queue;
use App\User\User;
use App\User\UserRepository;
use DateTimeImmutable;

/** Email-дайджесты (ежедневный и еженедельный). */
final class DigestService
{
    public function __construct(
        private readonly Database $db,
        private readonly Queue $queue,
        private readonly ArticleRepository $articles,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly Config $config,
    ) {
    }

    /**
     * Поставить в очередь дайджесты пользователям с выбранной частотой.
     * Повторно одному пользователю не отправляется чаще, чем раз в период.
     */
    public function enqueueDue(string $frequency): int
    {
        $interval = $frequency === 'daily' ? '20 hours' : '6 days';
        $ids = $this->db->fetchColumn(
            "SELECT id FROM users
              WHERE digest_frequency = :f AND status = 'active' AND email_verified_at IS NOT NULL
                AND (last_digest_at IS NULL OR last_digest_at < now() - :interval::interval)
              ORDER BY id",
            ['f' => $frequency, 'interval' => $interval]
        );
        foreach ($ids as $id) {
            $this->queue->push(SendDigestJob::class, ['user_id' => (int) $id, 'frequency' => $frequency]);
        }

        return count($ids);
    }

    /** Собрать и отправить дайджест одному пользователю. */
    public function send(User $user, string $frequency): bool
    {
        if ($user->digestFrequency !== $frequency || !$user->isActive() || !$user->isEmailVerified()) {
            return false;
        }
        $since = new DateTimeImmutable($frequency === 'daily' ? '-1 day' : '-7 days');
        $articles = $this->articles->publishedSince($since, $frequency === 'daily' ? 6 : 8);
        $this->db->execute('UPDATE users SET last_digest_at = now() WHERE id = :id', ['id' => $user->id]);
        if (count($articles) < 1) {
            return false;
        }
        $subject = $frequency === 'daily' ? 'Главное за сутки' : 'Главное за неделю';
        $this->mail->queue($user->email, $subject, 'digest', [
            'name' => $user->firstName(),
            'frequency' => $frequency,
            'articles' => array_map(fn ($a): array => [
                'title' => $a->title,
                'lead' => $a->excerpt(160),
                'category' => $a->categoryName,
                'url' => $this->config->appUrl() . $a->url() . '?utm_source=digest&utm_medium=email&utm_campaign=' . $frequency,
                'cover' => $a->hasCover() ? $this->config->appUrl() . $a->coverUrl(640) : null,
                'minutes' => $a->readingMinutes,
                'paid' => $a->isPaid(),
            ], $articles),
        ], $user, true);

        return true;
    }

    public function user(int $id): ?User
    {
        return $this->users->find($id);
    }
}
