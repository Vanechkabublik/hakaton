<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Security\DatabaseBackup;

final class BackupCommand implements Command
{
    public function __construct(private readonly DatabaseBackup $backup)
    {
    }

    public function name(): string
    {
        return 'backup';
    }

    public function description(): string
    {
        return 'Создать дамп базы данных в storage/backups';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        if (!$this->backup->isAvailable()) {
            $out->error('pg_dump не найден');

            return 1;
        }
        $file = $this->backup->createFile((int) ($options['keep'] ?? 7));
        if ($file === null) {
            $out->error('Не удалось создать бэкап');

            return 1;
        }
        $out->info('Бэкап: ' . $file);

        return 0;
    }
}
