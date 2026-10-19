<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Database;
use App\Core\Database\Repository;
use DateTimeImmutable;

/** @extends Repository<Subscription> */
final class SubscriptionRepository extends Repository
{
    protected const TABLE = 'subscriptions';
    protected const ENTITY = Subscription::class;

    private const SELECT = 'SELECT s.*, p.name AS plan_name, p.tier AS plan_tier, p.period AS plan_period, p.price AS plan_price,
                                   u.email AS user_email, u.name AS user_name
                              FROM subscriptions s
                              JOIN plans p ON p.id = s.plan_id
                              JOIN users u ON u.id = s.user_id';

    public function find(int $id): ?Subscription
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);

        return $row === null ? null : Subscription::fromRow($row);
    }

    /** «Живая» подписка пользователя (триал/активна/просрочена); не обязательно дающая доступ. */
    public function findLiveForUser(int $userId): ?Subscription
    {
        $row = $this->db->fetchOne(
            self::SELECT . " WHERE s.user_id = :u AND s.status IN ('trialing','active','past_due') ORDER BY s.id DESC LIMIT 1",
            ['u' => $userId]
        );

        return $row === null ? null : Subscription::fromRow($row);
    }

    /** Подписка, которая прямо сейчас даёт доступ. */
    public function findAccessFor(int $userId, ?DateTimeImmutable $now = null): ?Subscription
    {
        $sub = $this->findLiveForUser($userId);

        return $sub !== null && $sub->grantsAccess($now) ? $sub : null;
    }

    public function latestForUser(int $userId): ?Subscription
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE s.user_id = :u ORDER BY s.id DESC LIMIT 1', ['u' => $userId]);

        return $row === null ? null : Subscription::fromRow($row);
    }

    /** @return list<Subscription> */
    public function historyForUser(int $userId): array
    {
        return Subscription::fromRows($this->db->fetchAll(self::SELECT . ' WHERE s.user_id = :u ORDER BY s.id DESC', ['u' => $userId]));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = new DateTimeImmutable();
        $this->db->update('subscriptions', $data, ['id' => $id]);
    }

    /**
     * Подписки, которым пора выставить счёт автопродления.
     *
     * @return list<Subscription>
     */
    public function dueForRenewal(int $limit = 50): array
    {
        return Subscription::fromRows($this->db->fetchAll(
            self::SELECT . " WHERE s.auto_renew AND s.canceled_at IS NULL AND s.payment_method_id IS NOT NULL
                  AND s.status IN ('active','past_due') AND s.next_attempt_at IS NOT NULL AND s.next_attempt_at <= now()
                ORDER BY s.next_attempt_at LIMIT :l",
            ['l' => $limit]
        ));
    }

    /**
     * Подписки, у которых доступ закончился и продления не будет.
     *
     * @return list<Subscription>
     */
    public function dueForExpiry(int $limit = 200): array
    {
        return Subscription::fromRows($this->db->fetchAll(
            self::SELECT . " WHERE (
                    (s.status = 'trialing' AND s.current_period_end <= now())
                 OR (s.status = 'active' AND s.current_period_end <= now()
                     AND NOT (s.auto_renew AND s.canceled_at IS NULL AND s.payment_method_id IS NOT NULL))
                 OR (s.status = 'past_due' AND COALESCE(s.grace_until, s.current_period_end) <= now())
                 OR (s.status = 'active' AND s.current_period_end <= now() - interval '5 days')
                ) ORDER BY s.current_period_end LIMIT :l",
            ['l' => $limit]
        ));
    }

    /**
     * Подписки, истекающие в ближайшие $withinHours часов (для напоминаний).
     *
     * @return list<Subscription>
     */
    public function endingWithin(int $withinHours): array
    {
        return Subscription::fromRows($this->db->fetchAll(
            self::SELECT . " WHERE s.status IN ('trialing','active') AND s.current_period_end > now()
                  AND s.current_period_end <= now() + make_interval(hours => :h)",
            ['h' => $withinHours]
        ));
    }

    public function reminderSent(int $subscriptionId, string $kind, DateTimeImmutable $periodEnd): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM subscription_reminders WHERE subscription_id = :s AND kind = :k AND period_end = :p',
            ['s' => $subscriptionId, 'k' => $kind, 'p' => $periodEnd]
        );
    }

    public function markReminderSent(int $subscriptionId, string $kind, DateTimeImmutable $periodEnd): void
    {
        $this->db->execute(
            'INSERT INTO subscription_reminders (subscription_id, kind, period_end) VALUES (:s, :k, :p) ON CONFLICT DO NOTHING',
            ['s' => $subscriptionId, 'k' => $kind, 'p' => $periodEnd]
        );
    }

    /**
     * @param array{q?:string,status?:string,tier?:string,source?:string} $filters
     * @return array{items:list<Subscription>,total:int}
     */
    public function adminSearch(array $filters, int $limit, int $offset): array
    {
        $where = ['TRUE'];
        $params = [];
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $where[] = '(u.email ILIKE :q OR u.name ILIKE :q)';
            $params['q'] = '%' . Database::likeEscape($q) . '%';
        }
        $state = (string) ($filters['status'] ?? '');
        if ($state === 'live') {
            $where[] = "s.status IN ('trialing','active','past_due')";
        } elseif (SubscriptionStatus::tryFrom($state) !== null) {
            $where[] = 's.status = :status';
            $params['status'] = $state;
        }
        if (PlanTier::tryFrom((string) ($filters['tier'] ?? '')) !== null) {
            $where[] = 'p.tier = :tier';
            $params['tier'] = $filters['tier'];
        }
        if (!empty($filters['source'])) {
            $where[] = 's.source = :source';
            $params['source'] = $filters['source'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN users u ON u.id = s.user_id WHERE {$sqlWhere}",
            $params
        );
        $rows = $this->db->fetchAll(
            self::SELECT . " WHERE {$sqlWhere} ORDER BY s.current_period_end DESC, s.id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        return ['items' => Subscription::fromRows($rows), 'total' => $total];
    }

    public function countLive(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','past_due') AND current_period_end > now() - interval '7 days'");
    }
}
