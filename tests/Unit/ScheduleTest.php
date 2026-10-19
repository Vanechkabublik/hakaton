<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Schedule\Schedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    public function testIntervalDueWhenNeverRunOrElapsed(): void
    {
        $schedule = Schedule::everyMinutes(5);
        $now = new DateTimeImmutable('2026-03-10 12:00:00');

        $this->assertTrue($schedule->isDue(null, $now));
        $this->assertFalse($schedule->isDue(new DateTimeImmutable('2026-03-10 11:57:00'), $now));
        $this->assertTrue($schedule->isDue(new DateTimeImmutable('2026-03-10 11:55:00'), $now));
    }

    public function testDailyRunsOncePerDayAfterConfiguredTime(): void
    {
        $schedule = Schedule::dailyAt('08:00');

        // до 08:00 сегодняшний запуск ещё не наступил — достаточно вчерашнего
        $this->assertFalse($schedule->isDue(new DateTimeImmutable('2026-03-09 08:00:05'), new DateTimeImmutable('2026-03-10 07:59:00')));
        // после 08:00 — пора, если последний запуск был вчера
        $this->assertTrue($schedule->isDue(new DateTimeImmutable('2026-03-09 08:00:05'), new DateTimeImmutable('2026-03-10 08:00:01')));
        // уже запускалась сегодня
        $this->assertFalse($schedule->isDue(new DateTimeImmutable('2026-03-10 08:00:05'), new DateTimeImmutable('2026-03-10 20:00:00')));
    }

    public function testWeeklyRunsOnConfiguredWeekday(): void
    {
        $schedule = Schedule::weeklyOn(1, '09:00'); // понедельник
        $lastMonday = new DateTimeImmutable('2026-03-09 09:00:10'); // 2026-03-09 — понедельник

        $this->assertFalse($schedule->isDue($lastMonday, new DateTimeImmutable('2026-03-12 10:00:00')), 'В четверг ещё рано');
        $this->assertTrue($schedule->isDue($lastMonday, new DateTimeImmutable('2026-03-16 09:01:00')), 'Следующий понедельник после 09:00');
        $this->assertFalse($schedule->isDue($lastMonday, new DateTimeImmutable('2026-03-16 08:59:00')), 'Понедельник, но до 09:00');
    }

    public function testNeverRunDailyAndWeeklyAreDueImmediately(): void
    {
        $now = new DateTimeImmutable('2026-03-10 12:00:00');
        $this->assertTrue(Schedule::dailyAt('03:00')->isDue(null, $now));
        $this->assertTrue(Schedule::weeklyOn(3, '10:00')->isDue(null, $now));
    }
}
