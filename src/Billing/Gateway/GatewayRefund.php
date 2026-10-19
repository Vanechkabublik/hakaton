<?php

declare(strict_types=1);

namespace App\Billing\Gateway;

use App\Billing\Money;

final class GatewayRefund
{
    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        public readonly string $status,
        public readonly int $amount,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['payment_id'] ?? ''),
            (string) ($data['status'] ?? 'pending'),
            Money::fromDecimal($data['amount']['value'] ?? 0),
        );
    }
}
