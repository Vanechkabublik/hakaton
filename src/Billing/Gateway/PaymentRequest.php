<?php

declare(strict_types=1);

namespace App\Billing\Gateway;

/** Параметры создания платежа, не зависящие от конкретной платёжной системы. */
final class PaymentRequest
{
    /**
     * @param 'redirect'|'embedded'|null $confirmation null для рекуррентного списания (без участия пользователя)
     * @param array<string,scalar> $metadata
     */
    public function __construct(
        public readonly int $amount,
        public readonly string $description,
        public readonly ?string $confirmation,
        public readonly ?string $returnUrl,
        public readonly bool $savePaymentMethod,
        public readonly ?string $paymentMethodId,
        public readonly array $metadata,
        public readonly ?string $receiptEmail,
        public readonly string $receiptItem,
    ) {
    }
}
