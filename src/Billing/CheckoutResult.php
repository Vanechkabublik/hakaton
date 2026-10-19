<?php

declare(strict_types=1);

namespace App\Billing;

final class CheckoutResult
{
    public function __construct(
        public readonly ?Payment $payment,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $widgetToken = null,
        public readonly bool $scheduledChange = false,
    ) {
    }
}
