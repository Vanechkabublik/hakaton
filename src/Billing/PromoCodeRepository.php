<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Database;
use App\Core\Database\Repository;

/** @extends Repository<PromoCode> */
final class PromoCodeRepository extends Repository
{
    protected const TABLE = 'promo_codes';
    protected const ENTITY = PromoCode::class;

    public function find(int $id): ?PromoCode
    {
        $p = parent::find($id);

        return $p instanceof PromoCode ? $p : null;
    }

    public function findByCode(string $code): ?PromoCode
    {
        $row = $this->db->fetchOne('SELECT * FROM promo_codes WHERE code = :c', ['c' => trim($code)]);

        return $row === null ? null : PromoCode::fromRow($row);
    }

    /** @return list<PromoCode> */
    public function all(): array
    {
        return PromoCode::fromRows($this->db->fetchAll('SELECT * FROM promo_codes ORDER BY id DESC'));
    }

    /** @param array<string,mixed> $data */
    public function save(?int $id, array $data): int
    {
        $row = [
            'code' => strtoupper(trim((string) $data['code'])),
            'description' => trim((string) ($data['description'] ?? '')),
            'discount_type' => $data['discount_type'] === 'fixed' ? 'fixed' : 'percent',
            'discount_value' => (int) $data['discount_value'],
            'max_uses' => ($data['max_uses'] ?? '') === '' ? null : (int) $data['max_uses'],
            'per_user_limit' => max(1, (int) ($data['per_user_limit'] ?? 1)),
            'valid_from' => !empty($data['valid_from']) ? new \DateTimeImmutable((string) $data['valid_from']) : null,
            'valid_until' => !empty($data['valid_until']) ? new \DateTimeImmutable((string) $data['valid_until'] . ' 23:59:59') : null,
            'plan_ids' => array_values(array_map('intval', (array) ($data['plan_ids'] ?? []))),
            'first_payment_only' => !empty($data['first_payment_only']),
            'is_active' => !empty($data['is_active']),
        ];
        if ($id === null) {
            return $this->create($row);
        }
        $this->updateById($id, $row);

        return $id;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM promo_codes WHERE code = :c AND (:id::int IS NULL OR id <> :id)',
            ['c' => trim($code), 'id' => $exceptId]
        );
    }

    public function userRedemptions(int $promoId, int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = :p AND user_id = :u',
            ['p' => $promoId, 'u' => $userId]
        );
    }

    public function recordRedemption(int $promoId, ?int $userId, int $paymentId, int $discount): void
    {
        $this->db->transaction(function () use ($promoId, $userId, $paymentId, $discount): void {
            $exists = $this->db->fetchValue('SELECT 1 FROM promo_redemptions WHERE promo_id = :p AND payment_id = :pay', ['p' => $promoId, 'pay' => $paymentId]);
            if ($exists) {
                return;
            }
            $this->db->insert('promo_redemptions', ['promo_id' => $promoId, 'user_id' => $userId, 'payment_id' => $paymentId, 'discount' => $discount]);
            $this->db->execute('UPDATE promo_codes SET used_count = used_count + 1 WHERE id = :id', ['id' => $promoId]);
        });
    }

    /** Сколько рублей скидок выдано по каждому коду (для админки). @return array<int,int> */
    public function discountTotals(): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT promo_id, SUM(discount) AS s FROM promo_redemptions GROUP BY promo_id') as $row) {
            $result[(int) $row['promo_id']] = (int) $row['s'];
        }

        return $result;
    }

    public function delete(int $id): void
    {
        $this->deleteById($id);
    }
}
