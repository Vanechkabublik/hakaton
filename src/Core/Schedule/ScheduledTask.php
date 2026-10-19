<?php

declare(strict_types=1);

namespace App\Core\Schedule;

interface ScheduledTask
{
    public function name(): string;

    public function schedule(): Schedule;

    public function run(): void;
}
