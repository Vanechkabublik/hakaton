<?php

declare(strict_types=1);

namespace App\Core\Queue;

use App\Core\Container;
use App\Core\Logger;
use Throwable;

/** Выполняет задачи из очереди. Исключение в задаче → повтор с задержкой, после max_attempts → failed. */
final class Worker
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Container $container,
        private readonly Logger $logger,
    ) {
    }

    /** Обработать не более $limit задач; возвращает количество выполненных. */
    public function drain(int $limit = 100, string $queue = 'default'): int
    {
        $processed = 0;
        while ($processed < $limit) {
            $job = $this->queue->reserve($queue);
            if ($job === null) {
                break;
            }
            $this->process($job);
            ++$processed;
        }

        return $processed;
    }

    public function process(QueuedJob $queued): void
    {
        try {
            $handler = $this->container->get($queued->class);
            if (!$handler instanceof Job) {
                throw new \RuntimeException("{$queued->class} не реализует Job");
            }
            $handler->handle($queued->payload);
            $this->queue->complete($queued);
        } catch (Throwable $e) {
            $this->logger->error('Ошибка выполнения задачи', ['job' => $queued->class, 'id' => $queued->id, 'attempt' => $queued->attempts, 'exception' => $e]);
            $this->queue->fail($queued, $e::class . ': ' . $e->getMessage());
        }
    }
}
