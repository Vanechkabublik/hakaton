<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Core\Schedule\Scheduler;

final class ScheduleRunCommand implements Command
{
    public function __construct(private readonly Scheduler $scheduler)
    {
    }

    public function name(): string
    {
        return 'schedule:run';
    }

    public function description(): string
    {
        return 'Выполнить задачи планировщика, которым пришло время (--force — все)';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $executed = $this->scheduler->runDue(isset($options['force']));
        $out->line($executed === [] ? 'Нет задач к запуску' : 'Выполнено: ' . implode(', ', $executed));

        return 0;
    }
}
