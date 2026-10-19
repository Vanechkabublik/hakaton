<?php

declare(strict_types=1);

namespace App\Billing;

/** Расчёт стоимости покупки для страницы оформления. */
final class Quote
{
    public function __construct(
        public readonly Plan $plan,
        public readonly PaymentKind $kind,
        /** Цена тарифа */
        public readonly int $price,
        /** Зачёт неиспользованного остатка при апгрейде */
        public readonly int $credit,
        public readonly int $discount,
        /** Итог к оплате */
        public readonly int $total,
        public readonly ?PromoCode $promo,
        public readonly ?string $promoError,
        public readonly ?Subscription $current,
        /** Понижение тарифа: оплата не нужна, смена произойдёт в конце периода */
        public readonly bool $isScheduledChange,
    ) {
    }
}
