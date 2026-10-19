<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Database\Database;

/** Очередь задач в PostgreSQL (FOR UPDATE SKIP LOCKED — безопасно для нескольких воркеров). */
final class Queue
{
    private const STALE_MINUTES = 10;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param class-string<Job> $job
     * @param array<string,mixed> $payload
     */
    public function push(string $job, array $payload = [], int $delaySeconds = 0, string $queue = 'default', int $maxAttempts = 5): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO jobs (queue, job, payload, max_attempts, available_at)
             VALUES (:queue, :job, :payload, :max, now() + make_interval(secs => :delay)) RETURNING id',
            [
                'queue' => $queue,
                'job' => $job,
                'payload' => $payload,
                'max' => $maxAttempts,
                'delay' => $delaySeconds,
            ]
        );
    }

    /** Забрать следующую задачу (или null). */
    public function reserve(string $queue = 'default'): ?QueuedJob
    {
        $row = $this->db->fetchOne(
            'UPDATE jobs SET reserved_at = now(), attempts = attempts + 1
              WHERE id = (
                  SELECT id FROM jobs
                   WHERE queue = :queue AND failed_at IS NULL AND available_at <= now()
                     AND (reserved_at IS NULL OR reserved_at < now() - make_interval(mins => :stale))
                   ORDER BY available_at, id
                   FOR UPDATE SKIP LOCKED
                   LIMIT 1)
              RETURNING id, job, payload, attempts, max_attempts',
            ['queue' => $queue, 'stale' => self::STALE_MINUTES]
        );
        if ($row === null) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);

        return new QueuedJob((int) $row['id'], (string) $row['job'], is_array($payload) ? $payload : [], (int) $row['attempts'], (int) $row['max_attempts']);
    }

    public function complete(QueuedJob $job): void
    {
        $this->db->execute('DELETE FROM jobs WHERE id = :id', ['id' => $job->id]);
    }

    public function fail(QueuedJob $job, string $error): void
    {
        $error = mb_substr($error, 0, 2000);
        if ($job->attempts >= $job->maxAttempts) {
            $this->db->execute('UPDATE jobs SET failed_at = now(), reserved_at = NULL, last_error = :e WHERE id = :id', ['e' => $error, 'id' => $job->id]);

            return;
        }
        // экспоненциальная задержка: 1, 4, 9... минут
        $delay = $job->attempts * $job->attempts * 60;
        $this->db->execute(
            'UPDATE jobs SET reserved_at = NULL, last_error = :e, available_at = now() + make_interval(secs => :d) WHERE id = :id',
            ['e' => $error, 'd' => $delay, 'id' => $job->id]
        );
    }

    public function pendingCount(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs WHERE failed_at IS NULL');
    }

    public function failedCount(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs WHERE failed_at IS NOT NULL');
    }

    /** Вернуть провалившиеся задачи в очередь. */
    public function retryFailed(): int
    {
        return $this->db->execute('UPDATE jobs SET failed_at = NULL, attempts = 0, available_at = now() WHERE failed_at IS NOT NULL');
    }

    public function purgeFailed(int $olderThanDays = 14): int
    {
        return $this->db->execute(
            'DELETE FROM jobs WHERE failed_at IS NOT NULL AND failed_at < now() - make_interval(days => :d)',
            ['d' => $olderThanDays]
        );
    }
}
