<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

interface ClockContract
{
    public function now(): string;
}

final class FixedClock implements ClockContract
{
    public function now(): string
    {
        return '2026-01-01';
    }
}

final class NeedsClock
{
    public function __construct(public readonly ClockContract $clock, public readonly int $retries = 3)
    {
    }
}

final class CycleA
{
    public function __construct(public readonly CycleB $b)
    {
    }
}

final class CycleB
{
    public function __construct(public readonly CycleA $a)
    {
    }
}

final class ContainerTest extends TestCase
{
    public function testAutowiresThroughBinding(): void
    {
        $c = new Container();
        $c->bind(ClockContract::class, FixedClock::class);

        $service = $c->get(NeedsClock::class);

        $this->assertSame('2026-01-01', $service->clock->now());
        $this->assertSame(3, $service->retries, 'Скалярный параметр берёт значение по умолчанию');
    }

    public function testGetReturnsSameInstance(): void
    {
        $c = new Container();
        $this->assertSame($c->get(FixedClock::class), $c->get(FixedClock::class));
    }

    public function testMakeCreatesNewInstanceAndAcceptsNamedParameters(): void
    {
        $c = new Container();
        $c->bind(ClockContract::class, FixedClock::class);

        $a = $c->make(NeedsClock::class, ['retries' => 7]);
        $b = $c->make(NeedsClock::class);

        $this->assertSame(7, $a->retries);
        $this->assertNotSame($a, $b);
    }

    public function testInstanceOverridesBinding(): void
    {
        $c = new Container();
        $c->bind(ClockContract::class, FixedClock::class);
        $fake = new class implements ClockContract {
            public function now(): string
            {
                return 'fake';
            }
        };
        $c->instance(ClockContract::class, $fake);

        $this->assertSame('fake', $c->get(NeedsClock::class)->clock->now());
    }

    public function testSingletonFactoryRunsOnce(): void
    {
        $c = new Container();
        $calls = 0;
        $c->singleton(FixedClock::class, function () use (&$calls): FixedClock {
            ++$calls;

            return new FixedClock();
        });

        $c->get(FixedClock::class);
        $c->get(FixedClock::class);

        $this->assertSame(1, $calls);
    }

    public function testUnboundInterfaceFails(): void
    {
        $this->expectException(RuntimeException::class);
        (new Container())->get(ClockContract::class);
    }

    public function testCircularDependencyIsDetected(): void
    {
        $this->expectException(RuntimeException::class);
        (new Container())->get(CycleA::class);
    }

    public function testCallInjectsDependenciesAndNamedArguments(): void
    {
        $c = new Container();
        $c->bind(ClockContract::class, FixedClock::class);

        $result = $c->call(static fn (ClockContract $clock, string $suffix): string => $clock->now() . $suffix, ['suffix' => '!']);

        $this->assertSame('2026-01-01!', $result);
    }
}
