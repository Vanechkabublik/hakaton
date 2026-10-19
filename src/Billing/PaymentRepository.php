<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Database;
use App\Core\Database\Repository;
use DateTimeImmutable;

/** @extends Repository<Payment> */
final class PaymentRepository extends Repository
{
    protected const TABLE = 'payments';
    protected const ENTITY = Payment::class;

    private const SELECT = 'SELECT pay.*, pl.name AS plan_name, u.email AS user_email, u.name AS user_name
                              FROM payments pay
                              LEFT JOIN plans pl ON pl.id = pay.plan_id
                              LEFT JOIN users u ON u.id = pay.user_id';

    public function find(int $id): ?Payment
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE pay.id = :id', ['id' => $id]);

        return $row === null ? null : Payment::fromRow($row);
    }

    public function idByIdempotenceKey(string $key): ?int
    {
        $id = $this->db->fetchValue('SELECT id FROM payments WHERE idempotence_key = :k', ['k' => $key]);

        return $id === null ? null : (int) $id;
    }

    public function findByYookassaId(string $id): ?Payment
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE pay.yookassa_id = :y', ['y' => $id]);

        return $row === null ? null : Payment::fromRow($row);
    }

    /** Блокирует строку платежа до конца транзакции (защита от гонки вебхук/возврат со страницы оплаты). */
    public function lockForUpdate(int $id): ?Payment
    {
        $row = $this->db->fetchOne('SELECT * FROM payments WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : Payment::fromRow($row);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = new DateTimeImmutable();
        $this->db->update('payments', $data, ['id' => $id]);
    }

    /** @return list<Payment> */
    public function forUser(int $userId, int $limit = 100): array
    {
        return Payment::fromRows($this->db->fetchAll(
            self::SELECT . ' WHERE pay.user_id = :u ORDER BY pay.id DESC LIMIT :l',
            ['u' => $userId, 'l' => $limit]
        ));
    }

    /**
     * @param array{q?:string,status?:string,kind?:string,from?:string,to?:string} $filters
     * @return array{items:list<Payment>,total:int,sum:int}
     */
    public function adminSearch(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filters($filters);
        $totals = $this->db->fetchOne(
            "SELECT COUNT(*) AS c, COALESCE(SUM(CASE WHEN pay.status = 'succeeded' THEN pay.amount - pay.refunded_amount ELSE 0 END), 0) AS s
               FROM payments pay LEFT JOIN users u ON u.id = pay.user_id WHERE {$where}",
            $params
        ) ?? ['c' => 0, 's' => 0];
        $rows = $this->db->fetchAll(
            self::SELECT . " WHERE {$where} ORDER BY pay.id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        return ['items' => Payment::fromRows($rows), 'total' => (int) $totals['c'], 'sum' => (int) $totals['s']];
    }

    /** Генератор строк для CSV-экспорта. @return \Generator<int,array<string,mixed>> */
    public function exportRows(array $filters): \Generator
    {
        [$where, $params] = $this->filters($filters);
        $last = 0;
        while (true) {
            $rows = $this->db->fetchAll(
                self::SELECT . " WHERE {$where} AND pay.id > :last ORDER BY pay.id LIMIT 500",
                $params + ['last' => $last]
            );
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                yield $row;
            }
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function filters(array $filters): array
    {
        $where = ['TRUE'];
        $params = [];
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $where[] = '(u.email ILIKE :q OR pay.customer_email ILIKE :q OR pay.yookassa_id = :exact OR pay.id::text = :exact)';
            $params['q'] = '%' . Database::likeEscape($q) . '%';
            $params['exact'] = $q;
        }
        if (PaymentStatus::tryFrom((string) ($filters['status'] ?? '')) !== null) {
            $where[] = 'pay.status = :status';
            $params['status'] = $filters['status'];
        } elseif (($filters['status'] ?? '') === 'refunded') {
            $where[] = 'pay.refunded_amount > 0';
        }
        if (PaymentKind::tryFrom((string) ($filters['kind'] ?? '')) !== null) {
            $where[] = 'pay.kind = :kind';
            $params['kind'] = $filters['kind'];
        }
        if (!empty($filters['from']) && strtotime((string) $filters['from']) !== false) {
            $where[] = 'pay.created_at >= :from';
            $params['from'] = new DateTimeImmutable((string) $filters['from']);
        }
        if (!empty($filters['to']) && strtotime((string) $filters['to']) !== false) {
            $where[] = 'pay.created_at < :to';
            $params['to'] = (new DateTimeImmutable((string) $filters['to']))->modify('+1 day');
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Зависшие платежи для сверки с ЮKassa.
     *
     * @return list<Payment>
     */
    public function stale(int $minutes = 30, int $limit = 100): array
    {
        return Payment::fromRows($this->db->fetchAll(
            self::SELECT . " WHERE pay.status IN ('pending','waiting_for_capture') AND pay.yookassa_id IS NOT NULL
                AND pay.created_at < now() - make_interval(mins => :m) AND pay.created_at > now() - interval '3 days'
              ORDER BY pay.id LIMIT :l",
            ['m' => $minutes, 'l' => $limit]
        ));
    }

    /** Платежи за период (для сверки). @return list<Payment> */
    public function recentWithYookassaId(int $days, int $limit = 300): array
    {
        return Payment::fromRows($this->db->fetchAll(
            self::SELECT . ' WHERE pay.yookassa_id IS NOT NULL AND pay.created_at > now() - make_interval(days => :d) ORDER BY pay.id DESC LIMIT :l',
            ['d' => $days, 'l' => $limit]
        ));
    }

    /** Сумма (за вычетом возвратов) последнего успешного платежа по подписке. */
    public function lastPaidAmountForSubscription(int $subscriptionId): ?int
    {
        $value = $this->db->fetchValue(
            "SELECT amount - refunded_amount FROM payments
              WHERE subscription_id = :s AND status = 'succeeded' AND kind <> 'gift' ORDER BY paid_at DESC NULLS LAST, id DESC LIMIT 1",
            ['s' => $subscriptionId]
        );

        return $value === null ? null : (int) $value;
    }

    public function successfulCountForUser(int $userId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM payments WHERE user_id = :u AND status = 'succeeded' AND kind <> 'gift'", ['u' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    public function refundsFor(int $paymentId): array
    {
        return $this->db->fetchAll('SELECT r.*, u.name AS admin_name FROM refunds r LEFT JOIN users u ON u.id = r.created_by WHERE r.payment_id = :p ORDER BY r.id DESC', ['p' => $paymentId]);
    }
}
