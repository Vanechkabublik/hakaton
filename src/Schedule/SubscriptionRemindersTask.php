<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Billing\RenewalService;
use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;

final class SubscriptionRemindersTask implements ScheduledTask
{
    public function __construct(private readonly RenewalService $renewals)
    {
    }

    public function name(): string
    {
        return 'subscriptions:reminders';
    }

    public function schedule(): Schedule
    {
        return Schedule::everyMinutes(30);
    }

    public function run(): void
    {
        $this->renewals->sendReminders();
    }
}
