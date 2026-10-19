<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Billing\Gateway\PaymentGateway;
use App\Billing\PaymentProcessor;
use App\Billing\PaymentRepository;
use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;

/** Страховка на случай потерянных вебхуков: сверяет зависшие платежи с ЮKassa. */
final class SyncPaymentsTask implements ScheduledTask
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentProcessor $processor,
        private readonly PaymentGateway $gateway,
    ) {
    }

    public function name(): string
    {
        return 'payments:sync-stale';
    }

    public function schedule(): Schedule
    {
        return Schedule::everyMinutes(15);
    }

    public function run(): void
    {
        if ($this->gateway->isConfigured()) {
            $this->processor->reconcile($this->payments->stale(20));
        }
    }
}
