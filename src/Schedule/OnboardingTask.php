<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;
use App\Notification\OnboardingService;

final class OnboardingTask implements ScheduledTask
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function name(): string
    {
        return 'onboarding:process';
    }

    public function schedule(): Schedule
    {
        return Schedule::hourly();
    }

    public function run(): void
    {
        $this->onboarding->processDue();
    }
}
