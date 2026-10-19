<?php

declare(strict_types=1);

namespace App\Core\Schedule;

use App\Core\Container;
use App\Core\Database\Database;
use App\Core\Logger;
use DateTimeImmutable;
use Throwable;

/**
 * Запускает периодические задачи. Состояние хранится в БД (scheduled_runs),
 * захват задачи атомарен — безопасно держать несколько воркеров.
 */
final class Scheduler
{
    /** @var list<class-string<ScheduledTask>> */
    private array $tasks = [];

    public function __construct(
        private readonly Container $container,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /** @param class-string<ScheduledTask> $class */
    public function add(string $class): void
    {
        $this->tasks[] = $class;
    }

    /** @return list<string> имена выполненных задач */
    public function runDue(bool $force = false): array
    {
        $executed = [];
        $now = new DateTimeImmutable();
        foreach ($this->tasks as $class) {
            $task = $this->container->get($class);
            $last = $this->lastRun($task->name());
            if (!$force && !$task->schedule()->isDue($last, $now)) {
                continue;
            }
            if (!$this->claim($task->name(), $last)) {
                continue; // другой воркер уже взял
            }
            $started = microtime(true);
            try {
                $task->run();
                $executed[] = $task->name();
                $this->logger->debug('Задача выполнена', ['task' => $task->name(), 'ms' => (int) ((microtime(true) - $started) * 1000)]);
            } catch (Throwable $e) {
                $this->logger->error('Ошибка планировщика', ['task' => $task->name(), 'exception' => $e]);
            }
        }

        return $executed;
    }

    /** @return list<array{name:string,last_run:?DateTimeImmutable}> */
    public function status(): array
    {
        $rows = [];
        foreach ($this->tasks as $class) {
            $task = $this->container->get($class);
            $rows[] = ['name' => $task->name(), 'last_run' => $this->lastRun($task->name())];
        }

        return $rows;
    }

    private function lastRun(string $name): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue('SELECT last_run_at FROM scheduled_runs WHERE task = :t', ['t' => $name]);

        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private function claim(string $name, ?DateTimeImmutable $previous): bool
    {
        if ($previous === null) {
            return $this->db->execute(
                'INSERT INTO scheduled_runs (task, last_run_at) VALUES (:t, now()) ON CONFLICT (task) DO NOTHING',
                ['t' => $name]
            ) === 1;
        }

        return $this->db->execute(
            'UPDATE scheduled_runs SET last_run_at = now() WHERE task = :t AND last_run_at = :prev',
            ['t' => $name, 'prev' => $previous]
        ) === 1;
    }
}
