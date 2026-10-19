<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Core\Database\Repository;
use DateTimeImmutable;

/** @extends Repository<Campaign> */
final class CampaignRepository extends Repository
{
    protected const TABLE = 'campaigns';
    protected const ENTITY = Campaign::class;

    public function find(int $id): ?Campaign
    {
        $c = parent::find($id);

        return $c instanceof Campaign ? $c : null;
    }

    /** @return list<Campaign> */
    public function all(): array
    {
        return Campaign::fromRows($this->db->fetchAll('SELECT * FROM campaigns ORDER BY id DESC LIMIT 200'));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = new DateTimeImmutable();
        $this->db->update('campaigns', $data, ['id' => $id]);
    }

    /**
     * Условия сегмента.
     *
     * @param array<string,mixed> $segment
     * @return array{0:string,1:array<string,mixed>}
     */
    private function segmentWhere(array $segment): array
    {
        $where = ["u.status = 'active'", 'u.email_verified_at IS NOT NULL', 'u.marketing_opt_in'];
        $live = "EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status IN ('active','trialing','past_due') AND s.current_period_end > now())";
        switch ($segment['subscription'] ?? 'all') {
            case 'active':
                $where[] = "EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status IN ('active','past_due') AND s.current_period_end > now())";
                break;
            case 'trial':
                $where[] = "EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'trialing' AND s.current_period_end > now())";
                break;
            case 'none':
                $where[] = 'NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id)';
                break;
            case 'expired':
                $where[] = "NOT {$live} AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id)";
                break;
            case 'not_subscribed':
                $where[] = "NOT {$live}";
                break;
        }
        switch ($segment['activity'] ?? 'all') {
            case 'active_30':
                $where[] = "u.last_login_at > now() - interval '30 days'";
                break;
            case 'inactive_30':
                $where[] = "(u.last_login_at IS NULL OR u.last_login_at < now() - interval '30 days')";
                break;
            case 'new_7':
                $where[] = "u.created_at > now() - interval '7 days'";
                break;
        }

        return [implode(' AND ', $where), []];
    }

    public function countRecipients(array $segment): int
    {
        [$where, $params] = $this->segmentWhere($segment);

        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM users u WHERE {$where}", $params);
    }

    /**
     * Следующая порция получателей (id пользователей) после $afterId.
     *
     * @param array<string,mixed> $segment
     * @return list<int>
     */
    public function recipientChunk(array $segment, int $afterId, int $limit): array
    {
        [$where, $params] = $this->segmentWhere($segment);

        return array_map('intval', $this->db->fetchColumn(
            "SELECT u.id FROM users u WHERE {$where} AND u.id > :after ORDER BY u.id LIMIT :l",
            $params + ['after' => $afterId, 'l' => $limit]
        ));
    }

    public function lastQueuedUserId(int $campaignId): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(user_id), 0) FROM campaign_deliveries WHERE campaign_id = :c', ['c' => $campaignId]);
    }

    /** @param list<int> $userIds */
    public function addDeliveries(int $campaignId, array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->db->execute(
                'INSERT INTO campaign_deliveries (campaign_id, user_id) VALUES (:c, :u) ON CONFLICT DO NOTHING',
                ['c' => $campaignId, 'u' => $userId]
            );
        }
    }

    public function markDelivery(int $campaignId, int $userId, string $status, ?string $error = null): void
    {
        $updated = $this->db->execute(
            "UPDATE campaign_deliveries SET status = :s, error = :e, sent_at = now() WHERE campaign_id = :c AND user_id = :u AND status = 'queued'",
            ['s' => $status, 'e' => $error !== null ? mb_substr($error, 0, 300) : null, 'c' => $campaignId, 'u' => $userId]
        );
        if ($updated > 0) {
            $column = $status === 'sent' ? 'sent_count' : 'failed_count';
            $this->db->execute("UPDATE campaigns SET {$column} = {$column} + 1 WHERE id = :c", ['c' => $campaignId]);
        }
    }

    public function pendingDeliveries(int $campaignId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM campaign_deliveries WHERE campaign_id = :c AND status = 'queued'", ['c' => $campaignId]);
    }

    /** @return list<Campaign> */
    public function dueToStart(): array
    {
        return Campaign::fromRows($this->db->fetchAll("SELECT * FROM campaigns WHERE status = 'scheduled' AND scheduled_at <= now()"));
    }

    /** @return list<Campaign> */
    public function sending(): array
    {
        return Campaign::fromRows($this->db->fetchAll("SELECT * FROM campaigns WHERE status = 'sending'"));
    }
}
