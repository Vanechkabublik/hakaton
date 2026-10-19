<?php

declare(strict_types=1);

namespace App\Billing;

/** Уровень тарифа: Premium включает всё, что даёт Digital. */
enum PlanTier: string
{
    case Digital = 'digital';
    case Premium = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::Digital => 'Digital',
            self::Premium => 'Premium',
        };
    }

    public function rank(): int
    {
        return $this === self::Premium ? 2 : 1;
    }

    public function covers(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
