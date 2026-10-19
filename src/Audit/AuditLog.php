<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Database\Database;
use App\Core\Http\RequestContext;

/** Журнал действий (вход, смена ролей, возвраты, правки настроек...) — раздел «Безопасность» в админке. */
final class AuditLog
{
    public function __construct(
        private readonly Database $db,
        private readonly RequestContext $context,
    ) {
    }

    /** @param array<string,mixed> $meta */
    public function record(string $action, ?int $userId, ?string $entityType = null, string|int|null $entityId = null, array $meta = []): void
    {
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'meta' => $meta,
            'ip' => $this->context->ip(),
        ]);
    }

    /**
     * @param array{action?:string,user_id?:int,q?:string} $filters
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['action'])) {
            $where[] = 'a.action LIKE :action';
            $params['action'] = Database::likeEscape((string) $filters['action']) . '%';
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = :uid';
            $params['uid'] = (int) $filters['user_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(a.action ILIKE :q OR a.meta::text ILIKE :q OR u.email ILIKE :q)';
            $params['q'] = '%' . Database::likeEscape((string) $filters['q']) . '%';
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id {$sqlWhere}", $params);
        $items = $this->db->fetchAll(
            "SELECT a.*, u.email AS user_email, u.name AS user_name
               FROM audit_log a LEFT JOIN users u ON u.id = a.user_id {$sqlWhere}
              ORDER BY a.id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM audit_log WHERE user_id = :u OR (entity_type = \'user\' AND entity_id = :e) ORDER BY id DESC LIMIT :l',
            ['u' => $userId, 'e' => (string) $userId, 'l' => $limit]
        );
    }

    public function purgeOlderThan(int $days): int
    {
        return $this->db->execute('DELETE FROM audit_log WHERE created_at < now() - make_interval(days => :d)', ['d' => $days]);
    }
}
