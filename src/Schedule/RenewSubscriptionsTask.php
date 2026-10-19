<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Billing\RenewalService;
use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;

/** Автопродление подписок и закрытие истёкших. */
final class RenewSubscriptionsTask implements ScheduledTask
{
    public function __construct(private readonly RenewalService $renewals)
    {
    }

    public function name(): string
    {
        return 'subscriptions:renew';
    }

    public function schedule(): Schedule
    {
        return Schedule::everyMinutes(5);
    }

    public function run(): void
    {
        $this->renewals->renewDue();
        $this->renewals->expireDue();
    }
}
