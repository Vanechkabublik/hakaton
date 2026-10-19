<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Core\Database\Migrator;

final class MigrateCommand implements Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Применить SQL-миграции';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $applied = $this->migrator->migrate();
        if ($applied === []) {
            $out->line('Нечего применять: схема актуальна.');
        }
        foreach ($applied as $name) {
            $out->info('Применена миграция ' . $name);
        }

        return 0;
    }
}
