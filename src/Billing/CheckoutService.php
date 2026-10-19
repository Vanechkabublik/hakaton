<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLog;
use App\Billing\Gateway\GatewayPayment;
use App\Billing\Gateway\PaymentGateway;
use App\Billing\Gateway\PaymentGatewayException;
use App\Billing\Gateway\PaymentRequest;
use App\Core\Config;
use App\Core\Database\Database;
use App\Core\Logger;
use App\Core\Security\Crypto;
use App\Settings\SettingsService;
use App\User\User;

/**
 * Оформление покупки: расчёт стоимости (промокод, зачёт остатка при апгрейде),
 * создание платежа в ЮKassa с редиректом либо встроенным виджетом.
 */
final class CheckoutService
{
    private const MIN_AMOUNT = 100; // 1 ₽ — минимальный платёж ЮKassa

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentRepository $payments,
        private readonly PlanRepository $plans,
        private readonly SubscriptionRepository $subs,
        private readonly SubscriptionService $subscriptions,
        private readonly PromoCodeService $promos,
        private readonly GiftRepository $gifts,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Database $db,
        private readonly AuditLog $audit,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->gateway->isConfigured();
    }

    public function recurringEnabled(): bool
    {
        return $this->settings->get('billing.recurring_enabled') !== '0';
    }

    /** Предварительный расчёт для страницы оформления. */
    public function quote(User $user, Plan $plan, string $promoCode = ''): Quote
    {
        $current = $this->subs->findAccessFor($user->id) ?? $this->subs->findLiveForUser($user->id);
        $kind = PaymentKind::Initial;
        $credit = 0;
        $scheduled = false;

        if ($current !== null && !$current->isTrial()) {
            if ($current->planId === $plan->id) {
                $kind = PaymentKind::Extension;
            } elseif ($this->isUpgrade($current, $plan)) {
                $kind = PaymentKind::Upgrade;
                $credit = min($plan->price - self::MIN_AMOUNT, $this->subscriptions->prorationCredit($current));
                $credit = max(0, $credit);
            } else {
                $scheduled = true;
            }
        }

        $afterCredit = max(self::MIN_AMOUNT, $plan->price - $credit);
        $promoResult = $scheduled ? new PromoResult(null, 0, null) : $this->promos->check($promoCode, $plan, $user, $afterCredit);
        $discount = $promoResult->discount;
        $total = $scheduled ? 0 : max(self::MIN_AMOUNT, $afterCredit - $discount);

        return new Quote(
            $plan,
            $kind,
            $plan->price,
            $credit,
            $total >= $afterCredit ? 0 : $afterCredit - $total,
            $total,
            $promoResult->promo,
            $promoResult->error,
            $current,
            $scheduled,
        );
    }

    /**
     * Создать платёж (или запланировать понижение тарифа).
     *
     * @param 'redirect'|'embedded' $confirmation
     * @throws PaymentGatewayException
     * @throws \DomainException
     */
    public function start(User $user, Plan $plan, string $promoCode, string $confirmation, bool $autoRenew, ?int $paywallArticleId = null): CheckoutResult
    {
        if (!$plan->isActive) {
            throw new \DomainException('Этот тариф больше не продаётся');
        }
        $quote = $this->quote($user, $plan, $promoCode);
        if ($quote->promoError !== null && trim($promoCode) !== '') {
            throw new \DomainException($quote->promoError);
        }
        if ($quote->isScheduledChange && $quote->current !== null) {
            $this->subscriptions->scheduleChange($quote->current, $plan);

            return new CheckoutResult(null, null, null, true);
        }
        $this->assertAvailable();

        $save = $autoRenew && $this->recurringEnabled();
        $payment = $this->createLocalPayment($user, $plan, $quote->kind, $quote->price, $quote->total, $quote->credit + $quote->discount, $quote->promo, $save, [
            'plan' => $plan->code,
            'kind' => $quote->kind->value,
        ], $paywallArticleId);

        return $this->dispatch($payment, $user, $plan->receiptDescription(), $confirmation);
    }

    /**
     * Покупка подарочной подписки.
     *
     * @param 'redirect'|'embedded' $confirmation
     */
    public function startGift(User $buyer, Plan $plan, string $recipientEmail, string $recipientName, string $message, string $promoCode, string $confirmation): CheckoutResult
    {
        if (!$plan->isActive) {
            throw new \DomainException('Этот тариф больше не продаётся');
        }
        $this->assertAvailable();
        $promo = $this->promos->check($promoCode, $plan, $buyer, $plan->price);
        if ($promo->error !== null && trim($promoCode) !== '') {
            throw new \DomainException($promo->error);
        }
        $total = max(self::MIN_AMOUNT, $plan->price - $promo->discount);

        $payment = $this->db->transaction(function () use ($buyer, $plan, $recipientEmail, $recipientName, $message, $promo, $total): Payment {
            $payment = $this->createLocalPayment($buyer, $plan, PaymentKind::Gift, $plan->price, $total, $plan->price - $total, $promo->promo, false, ['plan' => $plan->code, 'kind' => 'gift'], null);
            $this->gifts->create([
                'code' => GiftRepository::generateCode(),
                'plan_id' => $plan->id,
                'buyer_id' => $buyer->id,
                'buyer_name' => $buyer->name,
                'recipient_email' => mb_strtolower(trim($recipientEmail)),
                'recipient_name' => mb_substr(trim($recipientName), 0, 120),
                'message' => mb_substr(trim($message), 0, 1000),
                'payment_id' => $payment->id,
            ]);

            return $payment;
        });

        return $this->dispatch($payment, $buyer, 'Подарочная ' . mb_strtolower($plan->receiptDescription()), $confirmation);
    }

    /** Повторить получение ссылки на оплату для незавершённого платежа. */
    public function resume(Payment $payment): ?CheckoutResult
    {
        if ($payment->status !== PaymentStatus::Pending || $payment->yookassaId === null) {
            return null;
        }
        if ($payment->confirmationType === 'redirect' && $payment->confirmationUrl !== null) {
            return new CheckoutResult($payment, $payment->confirmationUrl);
        }
        if ($payment->confirmationType === 'embedded' && $payment->confirmationToken !== null) {
            return new CheckoutResult($payment, null, $payment->confirmationToken);
        }

        return null;
    }

    private function assertAvailable(): void
    {
        if (!$this->gateway->isConfigured()) {
            throw new PaymentGatewayException('Оплата временно недоступна. Мы уже работаем над этим.');
        }
    }

    /** @param array<string,scalar> $metadata */
    private function createLocalPayment(
        User $user,
        Plan $plan,
        PaymentKind $kind,
        int $original,
        int $total,
        int $discount,
        ?PromoCode $promo,
        bool $saveMethod,
        array $metadata,
        ?int $paywallArticleId,
    ): Payment {
        $id = $this->payments->create([
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'plan_id' => $plan->id,
            'promo_code_id' => $promo?->id,
            'kind' => $kind->value,
            'status' => PaymentStatus::Pending->value,
            'amount' => $total,
            'original_amount' => $original,
            'discount_amount' => max(0, $discount),
            'description' => $plan->receiptDescription(),
            'idempotence_key' => Crypto::token(16),
            'save_method' => $saveMethod,
            'paywall_article_id' => $paywallArticleId,
            'metadata' => $metadata,
        ]);

        return $this->payments->find($id) ?? throw new \RuntimeException('Платёж не создан');
    }

    /** @param 'redirect'|'embedded' $confirmation */
    private function dispatch(Payment $payment, User $user, string $description, string $confirmation): CheckoutResult
    {
        $confirmation = $confirmation === 'embedded' ? 'embedded' : 'redirect';
        $metadata = $payment->metadata + ['payment_id' => (string) $payment->id, 'user_id' => (string) $user->id];

        $build = fn (bool $save): PaymentRequest => new PaymentRequest(
            $payment->amount,
            $description . ' — ' . parse_url($this->config->appUrl(), PHP_URL_HOST),
            $confirmation,
            $this->config->appUrl() . '/payment/return/' . $payment->id,
            $save,
            null,
            $metadata,
            $user->email,
            $description,
        );

        try {
            try {
                $remote = $this->gateway->createPayment($build($payment->saveMethod), $payment->idempotenceKey);
            } catch (PaymentGatewayException $e) {
                if (!$payment->saveMethod || $e->httpStatus === null || $e->httpStatus >= 500) {
                    throw $e;
                }
                // Магазин мог не получить доступ к рекуррентным платежам: оформляем без сохранения карты
                $this->logger->warning('ЮKassa не приняла save_payment_method, повтор без автопродления', ['error' => $e->getMessage()]);
                $newKey = Crypto::token(16);
                $this->payments->update($payment->id, ['save_method' => false, 'idempotence_key' => $newKey]);
                $remote = $this->gateway->createPayment($build(false), $newKey);
            }
        } catch (PaymentGatewayException $e) {
            $this->payments->update($payment->id, ['status' => PaymentStatus::Canceled->value, 'cancel_reason' => 'gateway_error']);
            throw $e;
        }

        $this->payments->update($payment->id, [
            'yookassa_id' => $remote->id,
            'confirmation_type' => $remote->confirmationType,
            'confirmation_url' => $remote->confirmationUrl,
            'confirmation_token' => $remote->confirmationToken,
            'status' => $remote->status === 'succeeded' ? PaymentStatus::Pending->value : $this->mapStatus($remote)->value,
        ]);
        $this->audit->record('payment.created', $user->id, 'payment', $payment->id, ['amount' => $payment->amount, 'kind' => $payment->kind->value]);
        $fresh = $this->payments->find($payment->id) ?? $payment;

        return new CheckoutResult($fresh, $remote->confirmationUrl, $remote->confirmationToken);
    }

    private function mapStatus(GatewayPayment $remote): PaymentStatus
    {
        return PaymentStatus::tryFrom($remote->status) ?? PaymentStatus::Pending;
    }

    /** Апгрейд = более высокий уровень либо тот же уровень, но более длинный период. */
    private function isUpgrade(Subscription $current, Plan $target): bool
    {
        $currentPlan = $this->plans->find($current->planId);
        if ($currentPlan === null) {
            return true;
        }
        if ($target->tier->rank() !== $currentPlan->tier->rank()) {
            return $target->tier->rank() > $currentPlan->tier->rank();
        }

        return $target->period->months() > $currentPlan->period->months();
    }
}
