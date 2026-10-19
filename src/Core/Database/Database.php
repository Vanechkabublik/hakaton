<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config;
use BackedEnum;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Тонкая обёртка над PDO (PostgreSQL). Все значения передаются только как параметры —
 * конкатенации пользовательских данных в SQL нет нигде в проекте.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $depth = 0;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->config->string('DB_HOST', 'db'),
                $this->config->int('DB_PORT', 5432),
                $this->config->string('DB_NAME', 'news'),
            );
            $this->pdo = new PDO(
                $dsn,
                $this->config->string('DB_USER', 'news'),
                $this->config->string('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
            $this->pdo->exec("SET TIME ZONE 'UTC'");
        }

        return $this->pdo;
    }

    /** Закрыть соединение (используется долгоживущим воркером при переподключении). */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->depth = 0;
    }

    /** @param array<string,mixed>|list<mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':'), ...$this->bindable($value));
        }
        $statement->execute();

        return $statement;
    }

    /** @return array{0:mixed,1:int} */
    private function bindable(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value, PDO::PARAM_BOOL],
            is_int($value) => [$value, PDO::PARAM_INT],
            $value instanceof BackedEnum => [$value->value, is_int($value->value) ? PDO::PARAM_INT : PDO::PARAM_STR],
            $value instanceof DateTimeInterface => [
                (clone $value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
                PDO::PARAM_STR,
            ],
            is_array($value) => [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR],
            is_float($value) => [(string) $value, PDO::PARAM_STR],
            default => [(string) $value, PDO::PARAM_STR],
        };
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed>|list<mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return list<mixed>
     */
    public function fetchColumn(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string,mixed>|list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * INSERT с возвратом значения первичного ключа.
     *
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data, string $returning = 'id'): int|string|null
    {
        $columns = array_keys($data);
        $this->assertIdentifiers([$table, $returning, ...$columns]);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
            $table,
            implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
            $returning,
        );

        return $this->fetchValue($sql, $data);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where условия равенства, объединённые через AND
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }
        $this->assertIdentifiers([$table, ...array_keys($data), ...array_keys($where)]);
        $params = [];
        $set = [];
        foreach ($data as $column => $value) {
            $set[] = '"' . $column . '" = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $conditions[] = '"' . $column . '" IS NULL';
                continue;
            }
            $conditions[] = '"' . $column . '" = :where_' . $column;
            $params['where_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), $conditions !== [] ? implode(' AND ', $conditions) : 'FALSE');

        return $this->execute($sql, $params);
    }

    /** @param array<string,mixed> $where */
    public function delete(string $table, array $where): int
    {
        $this->assertIdentifiers([$table, ...array_keys($where)]);
        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            $conditions[] = '"' . $column . '" = :' . $column;
            $params[$column] = $value;
        }

        return $this->execute(
            sprintf('DELETE FROM %s WHERE %s', $table, $conditions !== [] ? implode(' AND ', $conditions) : 'FALSE'),
            $params
        );
    }

    /**
     * Транзакция с поддержкой вложенности (SAVEPOINT).
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $savepoint = 'sp_' . $this->depth;
        if ($this->depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        ++$this->depth;
        try {
            $result = $callback();
            --$this->depth;
            if ($this->depth === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $e) {
            --$this->depth;
            if ($this->depth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
    }

    /**
     * Плейсхолдеры для IN (...): ['(:ids_0, :ids_1)', ['ids_0' => 1, 'ids_1' => 2]].
     *
     * @param list<scalar> $values
     * @return array{0:string,1:array<string,scalar>}
     */
    public static function in(string $prefix, array $values): array
    {
        if ($values === []) {
            return ['(NULL)', []];
        }
        $params = [];
        $names = [];
        foreach (array_values($values) as $i => $value) {
            $name = $prefix . '_' . $i;
            $names[] = ':' . $name;
            $params[$name] = $value;
        }

        return ['(' . implode(', ', $names) . ')', $params];
    }

    /** Экранирует спецсимволы LIKE. */
    public static function likeEscape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /** @param list<string> $identifiers */
    private function assertIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $identifier)) {
                throw new \InvalidArgumentException('Недопустимый SQL-идентификатор: ' . $identifier);
            }
        }
    }
}
