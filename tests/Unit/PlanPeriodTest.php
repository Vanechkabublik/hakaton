<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\PlanPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PlanPeriodTest extends TestCase
{
    public function testMonthClampsToLastDayOfShortMonth(): void
    {
        $this->assertSame('2027-02-28', PlanPeriod::Month->addTo(new DateTimeImmutable('2027-01-31'))->format('Y-m-d'));
        $this->assertSame('2028-02-29', PlanPeriod::Month->addTo(new DateTimeImmutable('2028-01-31'))->format('Y-m-d'));
        $this->assertSame('2026-04-30', PlanPeriod::Month->addTo(new DateTimeImmutable('2026-03-31'))->format('Y-m-d'));
    }

    public function testMonthKeepsTimeOfDay(): void
    {
        $this->assertSame('2026-06-15 13:45:10', PlanPeriod::Month->addTo(new DateTimeImmutable('2026-05-15 13:45:10'))->format('Y-m-d H:i:s'));
    }

    public function testMonthCrossesYearBoundary(): void
    {
        $this->assertSame('2027-01-20', PlanPeriod::Month->addTo(new DateTimeImmutable('2026-12-20'))->format('Y-m-d'));
    }

    public function testYearFromLeapDay(): void
    {
        $this->assertSame('2025-02-28', PlanPeriod::Year->addTo(new DateTimeImmutable('2024-02-29'))->format('Y-m-d'));
        $this->assertSame('2027-03-10', PlanPeriod::Year->addTo(new DateTimeImmutable('2026-03-10'))->format('Y-m-d'));
    }

    public function testMultiplePeriods(): void
    {
        $this->assertSame('2026-04-15', PlanPeriod::Month->addTo(new DateTimeImmutable('2026-01-15'), 3)->format('Y-m-d'));
        $this->assertSame('2028-01-15', PlanPeriod::Year->addTo(new DateTimeImmutable('2026-01-15'), 2)->format('Y-m-d'));
    }

    public function testSecondsFromIsPositiveAndMatchesLength(): void
    {
        $from = new DateTimeImmutable('2026-01-01 00:00:00');
        $this->assertSame(31 * 86400, PlanPeriod::Month->secondsFrom($from));
        $this->assertSame(365 * 86400, PlanPeriod::Year->secondsFrom($from));
    }
}
