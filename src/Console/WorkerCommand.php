<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Core\Database\Database;
use App\Core\Logger;
use App\Core\Queue\Worker;
use App\Core\Schedule\Scheduler;

/** Долгоживущий процесс: очередь задач + планировщик. Корректно завершается по SIGTERM. */
final class WorkerCommand implements Command
{
    private bool $stop = false;

    public function __construct(
        private readonly Worker $worker,
        private readonly Scheduler $scheduler,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function name(): string
    {
        return 'worker';
    }

    public function description(): string
    {
        return 'Запустить воркер: очередь и планировщик (--once — один проход)';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }
        $once = isset($options['once']);
        $started = time();
        $out->line('Воркер запущен');
        $this->logger->info('Воркер запущен');

        do {
            try {
                $executed = $this->scheduler->runDue();
                $processed = $this->worker->drain(50);
                if ($once) {
                    $out->line(sprintf('Задач в очереди обработано: %d; по расписанию: %s', $processed, $executed === [] ? '—' : implode(', ', $executed)));
                    break;
                }
                if ($processed === 0) {
                    sleep(2);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Сбой воркера', ['exception' => $e]);
                $this->db->disconnect();
                sleep(5);
            }
            // Регулярный перезапуск защищает от утечек памяти (контейнер поднимет процесс заново)
            if ((time() - $started) > 3600 * 6 || memory_get_usage(true) > 256 * 1024 * 1024) {
                $this->logger->info('Воркер перезапускается по лимитам');
                break;
            }
        } while (!$this->stop);

        $out->line('Воркер остановлен');

        return 0;
    }
}
