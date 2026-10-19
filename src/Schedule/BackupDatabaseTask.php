<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;
use App\Security\DatabaseBackup;

final class BackupDatabaseTask implements ScheduledTask
{
    public function __construct(private readonly DatabaseBackup $backup)
    {
    }

    public function name(): string
    {
        return 'maintenance:backup';
    }

    public function schedule(): Schedule
    {
        return Schedule::dailyAt('03:30');
    }

    public function run(): void
    {
        if ($this->backup->isAvailable()) {
            $this->backup->createFile(7);
        }
    }
}
