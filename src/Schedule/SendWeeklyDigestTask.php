<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;
use App\Notification\DigestService;

/** Еженедельный дайджест — по понедельникам в 09:00. */
final class SendWeeklyDigestTask implements ScheduledTask
{
    public function __construct(private readonly DigestService $digests)
    {
    }

    public function name(): string
    {
        return 'digest:weekly';
    }

    public function schedule(): Schedule
    {
        return Schedule::weeklyOn(1, '09:00');
    }

    public function run(): void
    {
        $this->digests->enqueueDue('weekly');
    }
}
