<?php

declare(strict_types=1);

namespace App\Core\Schedule;

use DateTimeImmutable;

/** Расписание задачи: интервал, раз в сутки в заданное время или раз в неделю. */
final class Schedule
{
    private function __construct(
        private readonly string $type,
        private readonly int $seconds = 0,
        private readonly int $dayOfWeek = 1,
        private readonly string $time = '00:00',
    ) {
    }

    public static function everySeconds(int $seconds): self
    {
        return new self('interval', $seconds);
    }

    public static function everyMinutes(int $minutes): self
    {
        return new self('interval', $minutes * 60);
    }

    public static function hourly(): self
    {
        return new self('interval', 3600);
    }

    /** Ежедневно в HH:MM по времени приложения. */
    public static function dailyAt(string $time): self
    {
        return new self('daily', 0, 1, $time);
    }

    /** Еженедельно: 1 = понедельник … 7 = воскресенье. */
    public static function weeklyOn(int $dayOfWeek, string $time): self
    {
        return new self('weekly', 0, $dayOfWeek, $time);
    }

    public function isDue(?DateTimeImmutable $lastRun, DateTimeImmutable $now): bool
    {
        if ($this->type === 'interval') {
            return $lastRun === null || ($now->getTimestamp() - $lastRun->getTimestamp()) >= $this->seconds;
        }
        $occurrence = $this->lastOccurrence($now);

        return $lastRun === null ? true : $lastRun < $occurrence;
    }

    /** Ближайшее запланированное время, не позже $now. */
    private function lastOccurrence(DateTimeImmutable $now): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $this->time) + [0, 0]);
        $candidate = $now->setTime($h, $m);
        if ($this->type === 'weekly') {
            $diff = ((int) $now->format('N') - $this->dayOfWeek + 7) % 7;
            $candidate = $candidate->modify("-{$diff} days");
            if ($candidate > $now) {
                $candidate = $candidate->modify('-7 days');
            }

            return $candidate;
        }

        return $candidate > $now ? $candidate->modify('-1 day') : $candidate;
    }
}
