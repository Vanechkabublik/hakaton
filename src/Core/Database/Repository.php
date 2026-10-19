<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Базовый репозиторий: общие операции для таблицы с первичным ключом id.
 *
 * @template T of Entity
 */
abstract class Repository
{
    /** Имя таблицы. */
    protected const TABLE = '';

    /** @var class-string<Entity> */
    protected const ENTITY = Entity::class;

    public function __construct(protected readonly Database $db)
    {
    }

    /** @return T|null */
    public function find(int $id): ?Entity
    {
        $row = $this->db->fetchOne('SELECT * FROM ' . static::TABLE . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row @return T */
    protected function hydrate(array $row): Entity
    {
        return (static::ENTITY)::fromRow($row);
    }

    /** @param list<array<string,mixed>> $rows @return list<T> */
    protected function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): Entity => $this->hydrate($row), $rows);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insert(static::TABLE, $data);
    }

    /** @param array<string,mixed> $data */
    public function updateById(int $id, array $data): void
    {
        $this->db->update(static::TABLE, $data, ['id' => $id]);
    }

    public function deleteById(int $id): void
    {
        $this->db->delete(static::TABLE, ['id' => $id]);
    }

    public function count(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . static::TABLE);
    }
}
