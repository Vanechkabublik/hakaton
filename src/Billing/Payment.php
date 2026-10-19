<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class Payment extends Entity
{
    public int $id = 0;
    public ?int $userId = null;
    public ?string $customerEmail = null;
    public ?int $subscriptionId = null;
    public ?int $planId = null;
    public ?int $promoCodeId = null;
    public PaymentKind $kind = PaymentKind::Initial;
    public PaymentStatus $status = PaymentStatus::Pending;
    public int $amount = 0;
    public int $originalAmount = 0;
    public int $discountAmount = 0;
    public int $refundedAmount = 0;
    public string $currency = 'RUB';
    public string $description = '';
    public ?string $yookassaId = null;
    public string $idempotenceKey = '';
    public ?string $confirmationType = null;
    public ?string $confirmationUrl = null;
    public ?string $confirmationToken = null;
    public bool $saveMethod = false;
    public bool $isRecurring = false;
    public ?string $paymentMethodId = null;
    public ?string $paymentMethodTitle = null;
    public ?string $cancelReason = null;
    public ?int $paywallArticleId = null;
    /** @var array<string,mixed> */
    public array $metadata = [];
    public ?DateTimeImmutable $paidAt = null;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    // из JOIN
    public ?string $planName = null;
    public ?string $userEmail = null;
    public ?string $userName = null;

    public function isSucceeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    public function refundableAmount(): int
    {
        return $this->isSucceeded() ? max(0, $this->amount - $this->refundedAmount) : 0;
    }

    /** Подпись статуса с учётом возвратов. */
    public function displayStatus(): string
    {
        if ($this->isSucceeded() && $this->refundedAmount > 0) {
            return $this->refundedAmount >= $this->amount ? 'Возвращён' : 'Частичный возврат';
        }

        return $this->status->label();
    }

    public function displayTone(): string
    {
        if ($this->isSucceeded() && $this->refundedAmount > 0) {
            return 'info';
        }

        return $this->status->tone();
    }
}
