<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class Subscription extends Entity
{
    public int $id = 0;
    public int $userId = 0;
    public int $planId = 0;
    public ?int $pendingPlanId = null;
    public SubscriptionStatus $status = SubscriptionStatus::Active;
    public string $source = 'payment';
    public DateTimeImmutable $startedAt;
    public DateTimeImmutable $currentPeriodStart;
    public DateTimeImmutable $currentPeriodEnd;
    public ?DateTimeImmutable $graceUntil = null;
    public bool $autoRenew = false;
    public ?string $paymentMethodId = null;
    public ?string $paymentMethodTitle = null;
    public int $renewAttempts = 0;
    public ?DateTimeImmutable $nextAttemptAt = null;
    public ?DateTimeImmutable $canceledAt = null;
    public ?string $cancelReason = null;
    public ?int $paywallArticleId = null;
    public ?int $grantedBy = null;
    public ?DateTimeImmutable $endedAt = null;
    public DateTimeImmutable $createdAt;

    // из JOIN с plans
    public ?string $planName = null;
    public ?string $planTier = null;
    public ?string $planPeriod = null;
    public ?int $planPrice = null;
    // из JOIN с users
    public ?string $userEmail = null;
    public ?string $userName = null;

    /** Даёт ли подписка доступ к материалам в момент $now. */
    public function grantsAccess(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return match ($this->status) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => $this->currentPeriodEnd > $now,
            SubscriptionStatus::PastDue => ($this->graceUntil ?? $this->currentPeriodEnd) > $now,
            SubscriptionStatus::Expired => false,
        };
    }

    public function tier(): ?PlanTier
    {
        return $this->planTier !== null ? PlanTier::tryFrom($this->planTier) : null;
    }

    public function willRenew(): bool
    {
        return $this->autoRenew && $this->paymentMethodId !== null && $this->canceledAt === null;
    }

    public function isTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }
}
