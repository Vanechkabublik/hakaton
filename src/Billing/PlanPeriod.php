<?php

declare(strict_types=1);

namespace App\Billing;

use DateTimeImmutable;

enum PlanPeriod: string
{
    case Month = 'month';
    case Year = 'year';

    public function label(): string
    {
        return $this === self::Month ? 'месяц' : 'год';
    }

    public function adjective(): string
    {
        return $this === self::Month ? 'Месячный' : 'Годовой';
    }

    public function months(): int
    {
        return $this === self::Month ? 1 : 12;
    }

    /** Прибавить период с «зажатием» дня месяца (31 января + месяц = 28/29 февраля). */
    public function addTo(DateTimeImmutable $from, int $times = 1): DateTimeImmutable
    {
        $day = (int) $from->format('j');
        $target = $from->modify('first day of this month')->modify('+' . ($this->months() * $times) . ' months');
        $lastDay = (int) $target->format('t');

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($day, $lastDay));
    }

    /** Длительность периода в секундах начиная с даты (для расчёта остатка). */
    public function secondsFrom(DateTimeImmutable $from): int
    {
        return $this->addTo($from)->getTimestamp() - $from->getTimestamp();
    }
}
