<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;
use App\Notification\DigestService;

/** Ежедневный дайджест — каждый день в 08:00. */
final class SendDigestsTask implements ScheduledTask
{
    public function __construct(private readonly DigestService $digests)
    {
    }

    public function name(): string
    {
        return 'digest:daily';
    }

    public function schedule(): Schedule
    {
        return Schedule::dailyAt('08:00');
    }

    public function run(): void
    {
        $this->digests->enqueueDue('daily');
    }
}
