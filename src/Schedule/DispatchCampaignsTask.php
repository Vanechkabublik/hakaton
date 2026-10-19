<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;
use App\Newsletter\CampaignService;

final class DispatchCampaignsTask implements ScheduledTask
{
    public function __construct(private readonly CampaignService $campaigns)
    {
    }

    public function name(): string
    {
        return 'campaigns:dispatch';
    }

    public function schedule(): Schedule
    {
        return Schedule::everyMinutes(1);
    }

    public function run(): void
    {
        $this->campaigns->dispatch();
    }
}
