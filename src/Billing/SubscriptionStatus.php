<?php

declare(strict_types=1);

namespace App\Billing;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Пробный период',
            self::Active => 'Активна',
            self::PastDue => 'Ожидает оплаты',
            self::Expired => 'Истекла',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Trialing => 'info',
            self::Active => 'success',
            self::PastDue => 'warning',
            self::Expired => 'muted',
        };
    }

    public function isLive(): bool
    {
        return $this !== self::Expired;
    }
}
