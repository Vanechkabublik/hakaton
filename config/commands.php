<?php

declare(strict_types=1);

use App\Console\BackupCommand;
use App\Console\DemoSeedCommand;
use App\Console\KeyGenerateCommand;
use App\Console\MigrateCommand;
use App\Console\ReconcilePaymentsCommand;
use App\Console\ScheduleRunCommand;
use App\Console\SeedCommand;
use App\Console\UserCreateAdminCommand;
use App\Console\UserResetTwoFactorCommand;
use App\Console\VapidGenerateCommand;
use App\Console\WorkerCommand;

return [
    MigrateCommand::class,
    SeedCommand::class,
    DemoSeedCommand::class,
    KeyGenerateCommand::class,
    VapidGenerateCommand::class,
    UserCreateAdminCommand::class,
    UserResetTwoFactorCommand::class,
    WorkerCommand::class,
    ScheduleRunCommand::class,
    BackupCommand::class,
    ReconcilePaymentsCommand::class,
];
