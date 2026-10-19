<?php

declare(strict_types=1);

namespace App\Paywall;

use App\Article\AccessLevel;
use App\Article\Article;
use App\Article\ContentPreview;
use App\Billing\SubscriptionService;
use App\Core\Database\Database;
use App\Settings\SettingsService;
use App\User\Gate;
use App\User\User;
use DateTimeImmutable;

/**
 * Метрированный пейволл: N бесплатных платных статей в месяц для гостей и читателей без подписки,
 * дальше — превью и призыв оформить подписку. Подписчики и сотрудники читают без ограничений.
 */
final class PaywallService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly Gate $gate,
        private readonly SettingsService $settings,
        private readonly Database $db,
    ) {
    }

    public function freeLimit(): int
    {
        return max(0, $this->settings->int('paywall.free_articles', 3));
    }

    /**
     * Решить, показывать ли статью целиком. При первом открытии платной статьи в рамках лимита
     * статья записывается в счётчик посетителя.
     */
    public function evaluate(Article $article, ?User $user, Visitor $visitor, bool $isBot): AccessDecision
    {
        if ($article->access === AccessLevel::Free) {
            return new AccessDecision(AccessDecision::FREE, true);
        }
        if ($user !== null && ($this->gate->allows($user, 'articles.edit_any') || $article->authorId === $user->id)) {
            return new AccessDecision(AccessDecision::STAFF, true);
        }
        if ($this->subscriptions->allows($user, $article->access)) {
            return new AccessDecision(AccessDecision::SUBSCRIBER, true);
        }
        if ($article->access === AccessLevel::Premium) {
            return new AccessDecision(AccessDecision::LOCKED, false, 0, 0, true);
        }

        // Digital: метрируемый доступ
        $limit = $this->freeLimit();
        if ($isBot || $limit === 0) {
            return new AccessDecision(AccessDecision::LOCKED, false, 0, $limit);
        }
        $month = $this->monthStart();
        $read = $this->readCount($visitor, $month);
        if ($this->alreadyRead($visitor, $article->id, $month)) {
            return new AccessDecision(AccessDecision::METERED, true, max(0, $limit - $read), $limit);
        }
        if ($read < $limit) {
            $this->db->execute(
                'INSERT INTO paywall_reads (identity, article_id, month) VALUES (:i, :a, :m) ON CONFLICT DO NOTHING',
                ['i' => $visitor->key(), 'a' => $article->id, 'm' => $month]
            );

            return new AccessDecision(AccessDecision::METERED, true, $limit - $read - 1, $limit);
        }

        return new AccessDecision(AccessDecision::LOCKED, false, 0, $limit);
    }

    /** Первые абзацы закрытой статьи. */
    public function preview(Article $article): string
    {
        $count = max(1, $this->settings->int('paywall.preview_paragraphs', 3));

        return ContentPreview::paragraphs($article->body, $count);
    }

    /** Сколько бесплатных статей осталось (для счётчика в шапке/на странице). */
    public function remaining(Visitor $visitor): int
    {
        return max(0, $this->freeLimit() - $this->readCount($visitor, $this->monthStart()));
    }

    public function recordShown(Article $article, Visitor $visitor): void
    {
        $this->db->execute(
            "INSERT INTO paywall_events (identity, user_id, article_id, event)
             SELECT :i, :u, :a, 'shown'
              WHERE NOT EXISTS (SELECT 1 FROM paywall_events WHERE identity = :i AND article_id = :a AND event = 'shown' AND created_at > now() - interval '1 day')",
            ['i' => $visitor->key(), 'u' => $visitor->userId, 'a' => $article->id]
        );
    }

    public function recordCtaClick(?int $articleId, Visitor $visitor): void
    {
        $this->db->insert('paywall_events', [
            'identity' => $visitor->key(),
            'user_id' => $visitor->userId,
            'article_id' => $articleId,
            'event' => 'cta_click',
        ]);
    }

    private function readCount(Visitor $visitor, string $month): int
    {
        [$in, $params] = Database::in('i', $visitor->identities());

        return (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT article_id) FROM paywall_reads WHERE month = :m AND identity IN {$in}",
            $params + ['m' => $month]
        );
    }

    private function alreadyRead(Visitor $visitor, int $articleId, string $month): bool
    {
        [$in, $params] = Database::in('i', $visitor->identities());

        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM paywall_reads WHERE month = :m AND article_id = :a AND identity IN {$in} LIMIT 1",
            $params + ['m' => $month, 'a' => $articleId]
        );
    }

    /** Первый день текущего месяца (в часовом поясе сайта). */
    private function monthStart(): string
    {
        return (new DateTimeImmutable('first day of this month'))->format('Y-m-01');
    }
}
