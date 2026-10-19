<?php

declare(strict_types=1);

namespace App\User;

use App\Core\Database\Database;
use App\Core\Database\Repository;
use App\Core\Security\Crypto;
use App\Support\Slug;

/** @extends Repository<User> */
final class UserRepository extends Repository
{
    protected const TABLE = 'users';
    protected const ENTITY = User::class;

    public function find(int $id): ?User
    {
        $user = parent::find($id);

        return $user instanceof User ? $user : null;
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE email = :email', ['email' => trim($email)]);

        return $row === null ? null : User::fromRow($row);
    }

    public function findBySlug(string $slug): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : User::fromRow($row);
    }

    public function findByUnsubscribeToken(string $token): ?User
    {
        $row = $this->db->fetchOne('SELECT * FROM users WHERE unsubscribe_token = :t', ['t' => $token]);

        return $row === null ? null : User::fromRow($row);
    }

    /** @param list<int> $ids @return array<int,User> */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Database::in('id', array_values(array_unique($ids)));
        $users = [];
        foreach ($this->db->fetchAll("SELECT * FROM users WHERE id IN {$in}", $params) as $row) {
            $user = User::fromRow($row);
            $users[$user->id] = $user;
        }

        return $users;
    }

    /**
     * @param array{email:string,name:string,password_hash?:?string,role?:string,email_verified?:bool,marketing_opt_in?:bool,digest_frequency?:string,avatar_path?:?string} $data
     */
    public function createUser(array $data): User
    {
        $id = $this->db->insert('users', [
            'email' => trim($data['email']),
            'name' => trim($data['name']),
            'password_hash' => $data['password_hash'] ?? null,
            'role' => $data['role'] ?? Role::Reader->value,
            'email_verified_at' => !empty($data['email_verified']) ? new \DateTimeImmutable() : null,
            'marketing_opt_in' => $data['marketing_opt_in'] ?? false,
            'digest_frequency' => $data['digest_frequency'] ?? 'weekly',
            'avatar_path' => $data['avatar_path'] ?? null,
            'unsubscribe_token' => Crypto::token(24),
        ]);

        return $this->find((int) $id) ?? throw new \RuntimeException('Не удалось создать пользователя');
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = new \DateTimeImmutable();
        $this->db->update('users', $data, ['id' => $id]);
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM users WHERE email = :email AND (:except::bigint IS NULL OR id <> :except)',
            ['email' => trim($email), 'except' => $exceptId]
        );
    }

    /** Гарантирует наличие публичного slug у автора. */
    public function ensureSlug(User $user): string
    {
        if ($user->slug !== null && $user->slug !== '') {
            return $user->slug;
        }
        $slug = Slug::unique($user->name, fn (string $s): bool => (bool) $this->db->fetchValue('SELECT 1 FROM users WHERE slug = :s', ['s' => $s]), 'author');
        $this->update($user->id, ['slug' => $slug]);
        $user->slug = $slug;

        return $slug;
    }

    /**
     * Поиск пользователей для админки.
     *
     * @param array{q?:string,role?:string,status?:string,subscription?:string} $filters
     * @return array{items:list<User>,total:int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM users u {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT u.* FROM users u {$where} ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        return ['items' => User::fromRows($rows), 'total' => $total];
    }

    /**
     * Потоковая выборка для экспорта (генератор, чтобы не держать всё в памяти).
     *
     * @param array<string,string> $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function exportRows(array $filters): \Generator
    {
        [$where, $params] = $this->buildFilters($filters);
        $last = 0;
        while (true) {
            $rows = $this->db->fetchAll(
                "SELECT u.id, u.email, u.name, u.role, u.status, u.email_verified_at, u.created_at, u.last_login_at,
                        (SELECT p.name || ' (' || s.status || ' до ' || to_char(s.current_period_end, 'YYYY-MM-DD') || ')'
                           FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                          WHERE s.user_id = u.id AND s.status IN ('active','trialing','past_due') LIMIT 1) AS subscription
                   FROM users u {$where} " . ($where === '' ? 'WHERE' : 'AND') . ' u.id > :last ORDER BY u.id LIMIT 500',
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

    /**
     * @param array<string,string> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = [];
        $params = [];
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $where[] = '(u.email ILIKE :q OR u.name ILIKE :q)';
            $params['q'] = '%' . Database::likeEscape($q) . '%';
        }
        if (!empty($filters['role']) && Role::tryFrom($filters['role']) !== null) {
            $where[] = 'u.role = :role';
            $params['role'] = $filters['role'];
        }
        if (!empty($filters['status']) && UserStatus::tryFrom($filters['status']) !== null) {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        $live = "EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status IN ('active','trialing','past_due'))";
        switch ($filters['subscription'] ?? '') {
            case 'active':
                $where[] = $live;
                break;
            case 'trial':
                $where[] = "EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'trialing')";
                break;
            case 'expired':
                $where[] = "NOT {$live} AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id)";
                break;
            case 'none':
                $where[] = 'NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id)';
                break;
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /** Авторы с опубликованными материалами (для страницы авторов и подписок). */
    public function listAuthors(): array
    {
        return User::fromRows($this->db->fetchAll(
            "SELECT u.* FROM users u
              WHERE u.role <> 'reader' AND u.status = 'active'
                AND EXISTS (SELECT 1 FROM articles a WHERE a.author_id = u.id AND a.status = 'published')
              ORDER BY u.name"
        ));
    }

    /** @return list<User> */
    public function listStaff(): array
    {
        return User::fromRows($this->db->fetchAll("SELECT * FROM users WHERE role <> 'reader' ORDER BY role DESC, name"));
    }

    public function countByRole(): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT role, COUNT(*) AS c FROM users GROUP BY role') as $row) {
            $result[(string) $row['role']] = (int) $row['c'];
        }

        return $result;
    }
}
