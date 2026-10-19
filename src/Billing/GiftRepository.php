<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Repository;
use DateTimeImmutable;

/** @extends Repository<Gift> */
final class GiftRepository extends Repository
{
    protected const TABLE = 'gifts';
    protected const ENTITY = Gift::class;

    public function find(int $id): ?Gift
    {
        $row = $this->db->fetchOne('SELECT g.*, p.name AS plan_name FROM gifts g JOIN plans p ON p.id = g.plan_id WHERE g.id = :id', ['id' => $id]);

        return $row === null ? null : Gift::fromRow($row);
    }

    public function findByCode(string $code): ?Gift
    {
        $row = $this->db->fetchOne(
            'SELECT g.*, p.name AS plan_name FROM gifts g JOIN plans p ON p.id = g.plan_id WHERE g.code = :c',
            ['c' => strtoupper(preg_replace('/\s+/', '', $code) ?? '')]
        );

        return $row === null ? null : Gift::fromRow($row);
    }

    public function findByPayment(int $paymentId): ?Gift
    {
        $row = $this->db->fetchOne('SELECT g.*, p.name AS plan_name FROM gifts g JOIN plans p ON p.id = g.plan_id WHERE g.payment_id = :p', ['p' => $paymentId]);

        return $row === null ? null : Gift::fromRow($row);
    }

    /** @return list<Gift> */
    public function forBuyer(int $userId): array
    {
        return Gift::fromRows($this->db->fetchAll(
            'SELECT g.*, p.name AS plan_name FROM gifts g JOIN plans p ON p.id = g.plan_id WHERE g.buyer_id = :u ORDER BY g.id DESC',
            ['u' => $userId]
        ));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('gifts', $data, ['id' => $id]);
    }

    /** Атомарно погасить подарок; false, если уже погашен/недоступен. */
    public function markRedeemed(int $id, int $userId): bool
    {
        return $this->db->execute(
            "UPDATE gifts SET status = 'redeemed', redeemed_by = :u, redeemed_at = now()
              WHERE id = :id AND status = 'paid' AND (expires_at IS NULL OR expires_at > now())",
            ['u' => $userId, 'id' => $id]
        ) === 1;
    }

    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; ++$i) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            if ($i % 4 === 3 && $i < 11) {
                $code .= '-';
            }
        }

        return $code;
    }

    public function expireOld(): int
    {
        return $this->db->execute("UPDATE gifts SET status = 'expired' WHERE status = 'paid' AND expires_at IS NOT NULL AND expires_at < now()");
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
