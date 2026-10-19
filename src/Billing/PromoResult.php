<?php

declare(strict_types=1);

namespace App\Billing;

final class PromoResult
{
    public function __construct(
        public readonly ?PromoCode $promo,
        public readonly int $discount,
        public readonly ?string $error,
    ) {
    }

    public function isValid(): bool
    {
        return $this->promo !== null && $this->error === null;
    }
}
