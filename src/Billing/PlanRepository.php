<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Repository;

/** @extends Repository<Plan> */
final class PlanRepository extends Repository
{
    protected const TABLE = 'plans';
    protected const ENTITY = Plan::class;

    public function find(int $id): ?Plan
    {
        $p = parent::find($id);

        return $p instanceof Plan ? $p : null;
    }

    public function findByCode(string $code): ?Plan
    {
        $row = $this->db->fetchOne('SELECT * FROM plans WHERE code = :c', ['c' => $code]);

        return $row === null ? null : Plan::fromRow($row);
    }

    /** @return list<Plan> */
    public function all(bool $activeOnly = false): array
    {
        return Plan::fromRows($this->db->fetchAll(
            'SELECT * FROM plans ' . ($activeOnly ? 'WHERE is_active ' : '') . 'ORDER BY sort_order, price'
        ));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function save(?int $id, array $data): int
    {
        $row = [
            'code' => strtolower(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'tier' => $data['tier'],
            'period' => $data['period'],
            'price' => (int) $data['price'],
            'trial_days' => (int) ($data['trial_days'] ?? 0),
            'description' => trim((string) ($data['description'] ?? '')),
            'features' => array_values(array_filter(array_map('trim', (array) ($data['features'] ?? [])), static fn (string $f): bool => $f !== '')),
            'is_active' => !empty($data['is_active']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
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
            'SELECT 1 FROM plans WHERE code = :c AND (:id::int IS NULL OR id <> :id)',
            ['c' => strtolower(trim($code)), 'id' => $exceptId]
        );
    }

    public function hasSubscriptions(int $planId): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM subscriptions WHERE plan_id = :p OR pending_plan_id = :p LIMIT 1',
            ['p' => $planId]
        ) || (bool) $this->db->fetchValue('SELECT 1 FROM payments WHERE plan_id = :p LIMIT 1', ['p' => $planId]);
    }
}
