<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class PromoCode extends Entity
{
    public int $id = 0;
    public string $code = '';
    public string $description = '';
    public string $discountType = 'percent';
    public int $discountValue = 0;
    public ?int $maxUses = null;
    public int $usedCount = 0;
    public int $perUserLimit = 1;
    public ?DateTimeImmutable $validFrom = null;
    public ?DateTimeImmutable $validUntil = null;
    /** @var array<int,int> */
    public array $planIds = [];
    public bool $firstPaymentOnly = true;
    public bool $isActive = true;
    public DateTimeImmutable $createdAt;

    /** Скидка в копейках для суммы $amount. */
    public function discountFor(int $amount): int
    {
        $discount = $this->discountType === 'percent'
            ? (int) floor($amount * min(100, $this->discountValue) / 100)
            : $this->discountValue;

        return min($amount, max(0, $discount));
    }

    public function label(): string
    {
        return $this->discountType === 'percent' ? '−' . $this->discountValue . '%' : '−' . intdiv($this->discountValue, 100) . ' ₽';
    }
}
