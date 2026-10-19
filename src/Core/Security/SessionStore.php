<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database\Database;

/** Хранилище сессий в PostgreSQL. В таблице лежит только SHA-256 идентификатора. */
final class SessionStore
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{data:array<string,mixed>,last_activity:int}|null */
    public function read(string $hash, int $maxIdleSeconds): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT payload, last_activity FROM sessions WHERE id = :id AND last_activity > :min',
            ['id' => $hash, 'min' => time() - $maxIdleSeconds]
        );
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['payload'], true);

        return ['data' => is_array($data) ? $data : [], 'last_activity' => (int) $row['last_activity']];
    }

    /** @param array<string,mixed> $data */
    public function write(string $hash, array $data, ?int $userId, string $ip, string $userAgent): void
    {
        $this->db->execute(
            'INSERT INTO sessions (id, user_id, payload, ip, user_agent, last_activity, created_at)
             VALUES (:id, :user_id, :payload, :ip, :ua, :now, now())
             ON CONFLICT (id) DO UPDATE SET user_id = EXCLUDED.user_id, payload = EXCLUDED.payload,
                 ip = EXCLUDED.ip, user_agent = EXCLUDED.user_agent, last_activity = EXCLUDED.last_activity',
            [
                'id' => $hash,
                'user_id' => $userId,
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'ip' => $ip,
                'ua' => $userAgent,
                'now' => time(),
            ]
        );
    }

    public function delete(string $hash): void
    {
        $this->db->execute('DELETE FROM sessions WHERE id = :id', ['id' => $hash]);
    }

    public function deleteForUser(int $userId, ?string $exceptHash = null): void
    {
        $this->db->execute(
            'DELETE FROM sessions WHERE user_id = :uid AND (:except::text IS NULL OR id <> :except)',
            ['uid' => $userId, 'except' => $exceptHash]
        );
    }

    public function gc(int $maxIdleSeconds): int
    {
        return $this->db->execute('DELETE FROM sessions WHERE last_activity < :min', ['min' => time() - $maxIdleSeconds]);
    }
}
