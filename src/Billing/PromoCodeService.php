<?php

declare(strict_types=1);

namespace App\Billing;

use App\User\User;
use DateTimeImmutable;

/** Проверка и применение промокодов. */
final class PromoCodeService
{
    public function __construct(
        private readonly PromoCodeRepository $promos,
        private readonly PaymentRepository $payments,
    ) {
    }

    /** Проверить код для тарифа и (необязательно) пользователя. $amount — сумма, к которой применяется скидка. */
    public function check(string $code, Plan $plan, ?User $user, int $amount): PromoResult
    {
        $code = trim($code);
        if ($code === '') {
            return new PromoResult(null, 0, null);
        }
        $promo = $this->promos->findByCode($code);
        $now = new DateTimeImmutable();
        $error = match (true) {
            $promo === null || !$promo->isActive => 'Промокод не найден',
            $promo->validFrom !== null && $promo->validFrom > $now => 'Промокод ещё не действует',
            $promo->validUntil !== null && $promo->validUntil < $now => 'Срок действия промокода истёк',
            $promo->maxUses !== null && $promo->usedCount >= $promo->maxUses => 'Лимит использований промокода исчерпан',
            $promo->planIds !== [] && !in_array($plan->id, $promo->planIds, true) => 'Промокод не действует для этого тарифа',
            default => null,
        };
        if ($error === null && $promo !== null && $user !== null) {
            if ($this->promos->userRedemptions($promo->id, $user->id) >= $promo->perUserLimit) {
                $error = 'Вы уже использовали этот промокод';
            } elseif ($promo->firstPaymentOnly && $this->payments->successfulCountForUser($user->id) > 0) {
                $error = 'Промокод действует только на первую оплату';
            }
        }
        if ($error !== null || $promo === null) {
            return new PromoResult(null, 0, $error ?? 'Промокод не найден');
        }

        return new PromoResult($promo, $promo->discountFor($amount), null);
    }

    public function redeem(Payment $payment): void
    {
        if ($payment->promoCodeId !== null && $payment->discountAmount > 0) {
            $this->promos->recordRedemption($payment->promoCodeId, $payment->userId, $payment->id, $payment->discountAmount);
        }
    }
}
