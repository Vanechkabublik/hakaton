<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class Gift extends Entity
{
    public int $id = 0;
    public string $code = '';
    public int $planId = 0;
    public ?int $buyerId = null;
    public string $buyerName = '';
    public string $recipientEmail = '';
    public string $recipientName = '';
    public string $message = '';
    public string $status = 'pending';
    public ?int $paymentId = null;
    public ?int $redeemedBy = null;
    public ?DateTimeImmutable $redeemedAt = null;
    public ?DateTimeImmutable $deliveredAt = null;
    public ?DateTimeImmutable $expiresAt = null;
    public DateTimeImmutable $createdAt;

    public ?string $planName = null;

    public function isRedeemable(): bool
    {
        return $this->status === 'paid' && ($this->expiresAt === null || $this->expiresAt > new DateTimeImmutable());
    }
}
