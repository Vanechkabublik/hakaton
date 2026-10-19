<?php

declare(strict_types=1);

namespace App\Billing\Gateway;

use App\Billing\Money;

/** Платёж в представлении платёжной системы (разобранный ответ API ЮKassa). */
final class GatewayPayment
{
    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly bool $paid,
        public readonly int $amount,
        public readonly int $refundedAmount,
        public readonly ?string $confirmationType,
        public readonly ?string $confirmationUrl,
        public readonly ?string $confirmationToken,
        public readonly ?string $paymentMethodId,
        public readonly bool $paymentMethodSaved,
        public readonly ?string $paymentMethodTitle,
        public readonly ?string $cancellationReason,
        public readonly array $metadata,
        public readonly bool $test,
        public readonly array $raw,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $method = is_array($data['payment_method'] ?? null) ? $data['payment_method'] : [];
        $confirmation = is_array($data['confirmation'] ?? null) ? $data['confirmation'] : [];
        $cancel = is_array($data['cancellation_details'] ?? null) ? $data['cancellation_details'] : [];

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['status'] ?? 'pending'),
            (bool) ($data['paid'] ?? false),
            Money::fromDecimal($data['amount']['value'] ?? 0),
            Money::fromDecimal($data['refunded_amount']['value'] ?? 0),
            isset($confirmation['type']) ? (string) $confirmation['type'] : null,
            isset($confirmation['confirmation_url']) ? (string) $confirmation['confirmation_url'] : null,
            isset($confirmation['confirmation_token']) ? (string) $confirmation['confirmation_token'] : null,
            isset($method['id']) ? (string) $method['id'] : null,
            (bool) ($method['saved'] ?? false),
            isset($method['title']) ? (string) $method['title'] : null,
            isset($cancel['reason']) ? (string) $cancel['reason'] : null,
            is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            (bool) ($data['test'] ?? false),
            $data,
        );
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }
}
