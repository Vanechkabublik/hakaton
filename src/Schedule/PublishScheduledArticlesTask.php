<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Article\ArticleService;
use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;

/** Отложенная публикация: переводит запланированные материалы в «опубликовано». */
final class PublishScheduledArticlesTask implements ScheduledTask
{
    public function __construct(private readonly ArticleService $articles)
    {
    }

    public function name(): string
    {
        return 'articles:publish-scheduled';
    }

    public function schedule(): Schedule
    {
        return Schedule::everyMinutes(1);
    }

    public function run(): void
    {
        $this->articles->publishDue();
    }
}
