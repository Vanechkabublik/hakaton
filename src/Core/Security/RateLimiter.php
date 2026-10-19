<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database\Database;

/**
 * Ограничение частоты действий (фиксированное окно). Счётчики лежат в PostgreSQL,
 * поэтому лимиты общие для всех процессов и контейнеров.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Зарегистрировать попытку и вернуть true, если лимит ещё не превышен.
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $row = $this->db->fetchOne(
            'INSERT INTO rate_limits (key, hits, expires_at)
             VALUES (:key, 1, now() + make_interval(secs => :window))
             ON CONFLICT (key) DO UPDATE SET
                 hits = CASE WHEN rate_limits.expires_at <= now() THEN 1 ELSE rate_limits.hits + 1 END,
                 expires_at = CASE WHEN rate_limits.expires_at <= now()
                                   THEN now() + make_interval(secs => :window) ELSE rate_limits.expires_at END
             RETURNING hits',
            ['key' => $this->normalize($key), 'window' => $windowSeconds]
        );

        return (int) ($row['hits'] ?? 1) <= $maxAttempts;
    }

    /** Превышен ли лимит (без регистрации новой попытки). */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $hits = $this->db->fetchValue(
            'SELECT hits FROM rate_limits WHERE key = :key AND expires_at > now()',
            ['key' => $this->normalize($key)]
        );

        return (int) $hits >= $maxAttempts;
    }

    /** Зарегистрировать неудачную попытку (для логина: считаем только ошибки). */
    public function hit(string $key, int $windowSeconds): int
    {
        $row = $this->db->fetchOne(
            'INSERT INTO rate_limits (key, hits, expires_at)
             VALUES (:key, 1, now() + make_interval(secs => :window))
             ON CONFLICT (key) DO UPDATE SET
                 hits = CASE WHEN rate_limits.expires_at <= now() THEN 1 ELSE rate_limits.hits + 1 END,
                 expires_at = CASE WHEN rate_limits.expires_at <= now()
                                   THEN now() + make_interval(secs => :window) ELSE rate_limits.expires_at END
             RETURNING hits',
            ['key' => $this->normalize($key), 'window' => $windowSeconds]
        );

        return (int) ($row['hits'] ?? 1);
    }

    public function retryAfter(string $key): int
    {
        $seconds = $this->db->fetchValue(
            'SELECT GREATEST(1, CEIL(EXTRACT(EPOCH FROM (expires_at - now())))::int) FROM rate_limits WHERE key = :key AND expires_at > now()',
            ['key' => $this->normalize($key)]
        );

        return (int) ($seconds ?? 0);
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE key = :key', ['key' => $this->normalize($key)]);
    }

    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM rate_limits WHERE expires_at <= now()');
    }

    private function normalize(string $key): string
    {
        return strlen($key) > 120 ? substr($key, 0, 60) . ':' . sha1($key) : $key;
    }
}
