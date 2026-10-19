<?php

declare(strict_types=1);

use App\Schedule\BackupDatabaseTask;
use App\Schedule\CleanupTask;
use App\Schedule\DispatchCampaignsTask;
use App\Schedule\OnboardingTask;
use App\Schedule\PublishScheduledArticlesTask;
use App\Schedule\RenewSubscriptionsTask;
use App\Schedule\SendDigestsTask;
use App\Schedule\SendWeeklyDigestTask;
use App\Schedule\SubscriptionRemindersTask;
use App\Schedule\SyncPaymentsTask;

return [
    PublishScheduledArticlesTask::class,
    DispatchCampaignsTask::class,
    RenewSubscriptionsTask::class,
    SubscriptionRemindersTask::class,
    SyncPaymentsTask::class,
    SendDigestsTask::class,
    SendWeeklyDigestTask::class,
    OnboardingTask::class,
    CleanupTask::class,
    BackupDatabaseTask::class,
];
