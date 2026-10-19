<?php

declare(strict_types=1);

namespace App\Console;

use App\Billing\PaymentProcessor;
use App\Billing\PaymentRepository;
use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;

final class ReconcilePaymentsCommand implements Command
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentProcessor $processor,
    ) {
    }

    public function name(): string
    {
        return 'payments:reconcile';
    }

    public function description(): string
    {
        return 'Сверить незавершённые платежи с ЮKassa';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $result = $this->processor->reconcile($this->payments->stale(0, 200));
        $out->line(sprintf('Проверено: %d, исправлено: %d, ошибок: %d', $result['checked'], $result['fixed'], $result['errors']));

        return $result['errors'] > 0 ? 1 : 0;
    }
}
