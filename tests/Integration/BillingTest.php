<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Billing\CheckoutService;
use App\Billing\Gateway\PaymentGatewayException;
use App\Billing\Payment;
use App\Billing\PaymentKind;
use App\Billing\PaymentRepository;
use App\Billing\PaymentStatus;
use App\Billing\Plan;
use App\Billing\PlanPeriod;
use App\Billing\PromoCodeRepository;
use App\Billing\RefundService;
use App\Billing\RenewalService;
use App\Billing\Subscription;
use App\Billing\SubscriptionRepository;
use App\Billing\SubscriptionService;
use App\Billing\SubscriptionStatus;
use App\User\User;
use DateTimeImmutable;
use Tests\Support\IntegrationTestCase;

final class BillingTest extends IntegrationTestCase
{
    private function checkout(): CheckoutService
    {
        return $this->c->get(CheckoutService::class);
    }

    private function subs(): SubscriptionRepository
    {
        return $this->c->get(SubscriptionRepository::class);
    }

    private function payments(): PaymentRepository
    {
        return $this->c->get(PaymentRepository::class);
    }

    /** Начать оплату и вернуть локальный платёж. */
    private function startPayment(User $user, Plan $plan, string $promo = '', bool $autoRenew = true): Payment
    {
        $result = $this->checkout()->start($user, $plan, $promo, 'redirect', $autoRenew);
        $this->assertNotNull($result->payment, 'Платёж должен быть создан');

        return $this->payments()->find($result->payment->id) ?? $result->payment;
    }

    /** Отправить вебхук ЮKassa так, как это делает сама ЮKassa. */
    private function webhook(string $event, string $objectId): \App\Core\Http\Response
    {
        return $this->postJson('/webhooks/yookassa', [
            'type' => 'notification',
            'event' => $event,
            'object' => ['id' => $objectId, 'status' => 'whatever-the-body-says'],
        ]);
    }

    /** Полный сценарий: оплата → вебхук. Возвращает живую подписку. */
    private function pay(User $user, Plan $plan, string $promo = '', bool $autoRenew = true): Subscription
    {
        $payment = $this->startPayment($user, $plan, $promo, $autoRenew);
        $this->gateway->succeed((string) $payment->yookassaId);
        $this->assertSame(200, $this->webhook('payment.succeeded', (string) $payment->yookassaId)->status());

        return $this->subs()->findLiveForUser($user->id) ?? $this->fail('Подписка не выдана после оплаты');
    }

    // ---- создание платежа -------------------------------------------------------------------

    public function testCheckoutCreatesPendingPaymentAndSendsCorrectRequest(): void
    {
        $user = $this->fx->user(['email' => 'buyer@example.test']);
        $plan = $this->fx->plan(['price' => 29900, 'name' => 'Digital']);

        $result = $this->checkout()->start($user, $plan, '', 'redirect', true);

        $this->assertNotNull($result->redirectUrl);
        $this->assertStringStartsWith('https://yookassa.test/pay/', $result->redirectUrl);
        $payment = $this->payments()->find($result->payment->id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(29900, $payment->amount);
        $this->assertSame(PaymentKind::Initial, $payment->kind);
        $this->assertNotNull($payment->yookassaId);

        $this->assertCount(1, $this->gateway->requests);
        $request = $this->gateway->requests[0];
        $this->assertSame(29900, $request->amount);
        $this->assertTrue($request->savePaymentMethod, 'Автопродление: способ оплаты сохраняется');
        $this->assertSame('buyer@example.test', $request->receiptEmail);
        $this->assertSame('redirect', $request->confirmation);
        $this->assertSame((string) $payment->id, (string) $request->metadata['payment_id']);
    }

    public function testEmbeddedWidgetReturnsToken(): void
    {
        $result = $this->checkout()->start($this->fx->user(), $this->fx->plan(), '', 'embedded', false);

        $this->assertNotNull($result->widgetToken);
        $this->assertNull($result->redirectUrl);
        $this->assertFalse($this->gateway->requests[0]->savePaymentMethod, 'Без галочки автопродления карта не сохраняется');
    }

    public function testInactivePlanCannotBePurchased(): void
    {
        $plan = $this->fx->plan(['is_active' => false]);

        $this->expectException(\DomainException::class);
        $this->checkout()->start($this->fx->user(), $plan, '', 'redirect', false);
    }

    public function testCheckoutFailsCleanlyWhenGatewayIsNotConfigured(): void
    {
        $this->gateway->configured = false;

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('Оплата временно недоступна');
        $this->checkout()->start($this->fx->user(), $this->fx->plan(), '', 'redirect', false);
    }

    // ---- вебхук и выдача доступа ------------------------------------------------------------

    public function testSuccessfulPaymentGrantsSubscriptionViaWebhook(): void
    {
        $user = $this->fx->user();
        $plan = $this->fx->plan(['period' => 'month', 'price' => 29900]);

        $sub = $this->pay($user, $plan);

        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame($plan->id, $sub->planId);
        $this->assertSame(PlanPeriod::Month->addTo($sub->currentPeriodStart)->format('Y-m-d H:i'), $sub->currentPeriodEnd->format('Y-m-d H:i'));
        $this->assertTrue($sub->autoRenew);
        $this->assertNotNull($sub->paymentMethodId, 'Способ оплаты сохранён для автопродления');
        $this->assertNotNull($sub->nextAttemptAt);

        $payment = $this->payments()->find((int) $this->db->fetchValue('SELECT id FROM payments LIMIT 1'));
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame($sub->id, $payment->subscriptionId);
        $this->assertNotNull($payment->paidAt);

        $mails = $this->mail->to($user->email);
        $this->assertNotEmpty($mails, 'Покупателю уходит письмо о успешной оплате');
    }

    public function testYearlyPlanLastsOneYear(): void
    {
        $sub = $this->pay($this->fx->user(), $this->fx->plan(['period' => 'year', 'price' => 249000]));

        $this->assertSame(PlanPeriod::Year->addTo($sub->currentPeriodStart)->format('Y-m-d'), $sub->currentPeriodEnd->format('Y-m-d'));
    }

    public function testRepeatedWebhookDoesNotExtendTwice(): void
    {
        $user = $this->fx->user();
        $payment = $this->startPayment($user, $this->fx->plan());
        $this->gateway->succeed((string) $payment->yookassaId);

        $this->webhook('payment.succeeded', (string) $payment->yookassaId);
        $first = $this->subs()->findLiveForUser($user->id);
        $this->webhook('payment.succeeded', (string) $payment->yookassaId);
        $this->webhook('payment.succeeded', (string) $payment->yookassaId);
        $second = $this->subs()->findLiveForUser($user->id);

        $this->assertEquals($first->currentPeriodEnd, $second->currentPeriodEnd, 'ЮKassa может присылать уведомление повторно');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM subscriptions'));
        $this->assertCount(1, array_filter($this->mail->to($user->email), static fn ($m): bool => str_contains($m->subject, 'Оплата')));
    }

    public function testWebhookBodyIsNotTrustedPaymentIsRefetched(): void
    {
        $user = $this->fx->user();
        $payment = $this->startPayment($user, $this->fx->plan());

        // злоумышленник присылает «успех», а в ЮKassa платёж всё ещё pending
        $response = $this->postJson('/webhooks/yookassa', [
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => ['id' => $payment->yookassaId, 'status' => 'succeeded', 'paid' => true],
        ]);

        $this->assertSame(200, $response->status());
        $this->assertNull($this->subs()->findLiveForUser($user->id), 'Доступ выдаётся только по данным из API');
        $this->assertSame(PaymentStatus::Pending, $this->payments()->find($payment->id)->status);
    }

    public function testCanceledPaymentGrantsNothing(): void
    {
        $user = $this->fx->user();
        $payment = $this->startPayment($user, $this->fx->plan());
        $this->gateway->cancel((string) $payment->yookassaId, 'insufficient_funds');

        $this->webhook('payment.canceled', (string) $payment->yookassaId);

        $this->assertNull($this->subs()->findLiveForUser($user->id));
        $fresh = $this->payments()->find($payment->id);
        $this->assertSame(PaymentStatus::Canceled, $fresh->status);
        $this->assertSame('insufficient_funds', $fresh->cancelReason);
    }

    public function testAmountMismatchIsRejected(): void
    {
        $user = $this->fx->user();
        $payment = $this->startPayment($user, $this->fx->plan(['price' => 29900]));
        $this->gateway->succeed((string) $payment->yookassaId);
        $this->gateway->tamperAmount((string) $payment->yookassaId, 100);

        $this->webhook('payment.succeeded', (string) $payment->yookassaId);

        $this->assertNull($this->subs()->findLiveForUser($user->id), 'Платёж на другую сумму не открывает доступ');
        $this->assertNotSame(PaymentStatus::Succeeded, $this->payments()->find($payment->id)->status);
    }

    public function testWebhookForUnknownPaymentIsAcknowledgedAndLogged(): void
    {
        $response = $this->webhook('payment.succeeded', 'does-not-exist');

        $this->assertSame(200, $response->status(), 'ЮKassa не должна бесконечно повторять доставку');
        $this->assertSame('ignored', $this->db->fetchValue("SELECT status FROM webhook_events WHERE object_id = 'does-not-exist'"));
    }

    public function testMalformedWebhookIsRejected(): void
    {
        $this->assertSame(400, $this->postJson('/webhooks/yookassa', ['hello' => 'world'])->status());
        $this->assertSame(400, $this->postJson('/webhooks/yookassa', ['type' => 'notification', 'event' => 'payment.succeeded'])->status());
    }

    public function testGatewayOutageDuringWebhookRequestsRedelivery(): void
    {
        $payment = $this->startPayment($this->fx->user(), $this->fx->plan());
        unset($this->gateway->payments[(string) $payment->yookassaId]); // шлюз «не знает» платёж → исключение

        $this->assertSame(500, $this->webhook('payment.succeeded', (string) $payment->yookassaId)->status(), 'Ответ не-200 заставит ЮKassa повторить уведомление');
        $this->assertSame('failed', $this->db->fetchValue("SELECT status FROM webhook_events ORDER BY id DESC LIMIT 1"));
    }

    // ---- продление, смена тарифа ----------------------------------------------------------

    public function testPayingSamePlanAgainExtendsFromCurrentPeriodEnd(): void
    {
        $user = $this->fx->user();
        $plan = $this->fx->plan();
        $first = $this->pay($user, $plan);

        $second = $this->pay($user, $plan);

        $this->assertSame($first->id, $second->id, 'Подписка одна');
        $this->assertSame(PlanPeriod::Month->addTo($first->currentPeriodEnd)->format('Y-m-d H:i'), $second->currentPeriodEnd->format('Y-m-d H:i'));
        $this->assertSame(PaymentKind::Extension, $this->payments()->find((int) $this->db->fetchValue('SELECT max(id) FROM payments'))->kind);
    }

    public function testUpgradeGetsProratedCredit(): void
    {
        $user = $this->fx->user();
        $cheap = $this->fx->plan(['tier' => 'digital', 'price' => 30000]);
        $premium = $this->fx->plan(['tier' => 'premium', 'price' => 60000]);
        $this->pay($user, $cheap);

        $quote = $this->checkout()->quote($user, $premium);

        $this->assertSame(PaymentKind::Upgrade, $quote->kind);
        $this->assertFalse($quote->isScheduledChange);
        $this->assertGreaterThan(29000, $quote->credit, 'Почти весь оплаченный месяц не использован');
        $this->assertLessThanOrEqual(30000, $quote->credit);
        $this->assertSame(60000 - $quote->credit, $quote->total);
    }

    public function testUpgradePaymentSwitchesPlanImmediately(): void
    {
        $user = $this->fx->user();
        $this->pay($user, $this->fx->plan(['tier' => 'digital', 'price' => 30000]));
        $premium = $this->fx->plan(['tier' => 'premium', 'price' => 60000]);

        $sub = $this->pay($user, $premium);

        $this->assertSame($premium->id, $sub->planId);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM subscriptions'));
        $this->assertLessThan(60000, (int) $this->db->fetchValue('SELECT amount FROM payments ORDER BY id DESC LIMIT 1'), 'Платим за вычетом остатка');
    }

    public function testDowngradeIsScheduledAndCostsNothing(): void
    {
        $user = $this->fx->user();
        $premium = $this->fx->plan(['tier' => 'premium', 'price' => 60000]);
        $digital = $this->fx->plan(['tier' => 'digital', 'price' => 30000]);
        $sub = $this->pay($user, $premium);

        $result = $this->checkout()->start($user, $digital, '', 'redirect', true);

        $this->assertTrue($result->scheduledChange);
        $this->assertNull($result->payment);
        $fresh = $this->subs()->find($sub->id);
        $this->assertSame($digital->id, $fresh->pendingPlanId);
        $this->assertSame($premium->id, $fresh->planId, 'Текущий тариф действует до конца периода');
        $this->assertCount(1, $this->gateway->requests, 'Нового платежа не создавалось');
    }

    // ---- промокоды -------------------------------------------------------------------------

    private function promo(array $overrides = []): int
    {
        return $this->c->get(PromoCodeRepository::class)->save(null, array_merge([
            'code' => 'WELCOME20', 'discount_type' => 'percent', 'discount_value' => 20, 'per_user_limit' => 1, 'is_active' => true,
        ], $overrides));
    }

    public function testPercentPromoReducesPrice(): void
    {
        $this->promo();
        $user = $this->fx->user();
        $plan = $this->fx->plan(['price' => 30000]);

        $quote = $this->checkout()->quote($user, $plan, 'welcome20');

        $this->assertNull($quote->promoError);
        $this->assertSame(6000, $quote->discount);
        $this->assertSame(24000, $quote->total);
    }

    public function testPromoIsChargedAndRedeemedOnlyAfterPayment(): void
    {
        $promoId = $this->promo(['max_uses' => 1]);
        $user = $this->fx->user();
        $plan = $this->fx->plan(['price' => 30000]);

        $payment = $this->startPayment($user, $plan, 'WELCOME20');
        $this->assertSame(24000, $payment->amount);
        $this->assertSame(0, $this->c->get(PromoCodeRepository::class)->find($promoId)->usedCount, 'Пока не оплачено — промокод не потрачен');

        $this->gateway->succeed((string) $payment->yookassaId);
        $this->webhook('payment.succeeded', (string) $payment->yookassaId);

        $this->assertSame(1, $this->c->get(PromoCodeRepository::class)->find($promoId)->usedCount);
        $other = $this->fx->user();
        $this->assertSame('Лимит использований промокода исчерпан', $this->checkout()->quote($other, $plan, 'WELCOME20')->promoError);
    }

    public function testPromoCanBeUsedOncePerUser(): void
    {
        $this->promo(['code' => 'ONCE', 'per_user_limit' => 1]);
        $plan = $this->fx->plan(['price' => 30000]);
        $user = $this->fx->user();
        $this->pay($user, $plan, 'ONCE');

        $again = $this->checkout()->quote($user, $plan, 'ONCE');
        $newcomer = $this->checkout()->quote($this->fx->user(), $plan, 'ONCE');

        $this->assertSame('Вы уже использовали этот промокод', $again->promoError);
        $this->assertNull($newcomer->promoError);
    }

    public function testInvalidPromoBlocksCheckout(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Промокод не найден');
        $this->checkout()->start($this->fx->user(), $this->fx->plan(), 'NOSUCH', 'redirect', false);
    }

    public function testExpiredAndPlanRestrictedPromos(): void
    {
        $plan = $this->fx->plan();
        $other = $this->fx->plan();
        $this->promo(['code' => 'OLD', 'valid_until' => '2020-01-01']);
        $this->promo(['code' => 'ONLYOTHER', 'plan_ids' => [$other->id]]);
        $user = $this->fx->user();

        $this->assertSame('Срок действия промокода истёк', $this->checkout()->quote($user, $plan, 'OLD')->promoError);
        $this->assertSame('Промокод не действует для этого тарифа', $this->checkout()->quote($user, $plan, 'ONLYOTHER')->promoError);
        $this->assertNull($this->checkout()->quote($user, $other, 'ONLYOTHER')->promoError);
    }

    public function testDiscountNeverGoesBelowMinimumPayment(): void
    {
        $this->promo(['code' => 'FREE', 'discount_type' => 'fixed', 'discount_value' => 10000000]);

        $quote = $this->checkout()->quote($this->fx->user(), $this->fx->plan(['price' => 30000]), 'FREE');

        $this->assertSame(100, $quote->total, 'Минимальный платёж ЮKassa — 1 ₽');
    }

    // ---- пробный период ------------------------------------------------------------------

    public function testTrialIsGrantedOnceWithoutCard(): void
    {
        $user = $this->fx->user();
        $plan = $this->fx->plan(['trial_days' => 7]);
        $service = $this->c->get(SubscriptionService::class);

        $this->assertTrue($service->canStartTrial($user, $plan));
        $trial = $service->startTrial($user, $plan);

        $this->assertSame(SubscriptionStatus::Trialing, $trial->status);
        $this->assertSame(7, (int) $trial->currentPeriodStart->diff($trial->currentPeriodEnd)->days);
        $this->assertFalse($trial->autoRenew);
        $this->assertCount(0, $this->gateway->requests, 'Карта для пробного периода не нужна');

        $fresh = $this->c->get(\App\User\UserRepository::class)->find($user->id);
        $this->assertFalse($service->canStartTrial($fresh, $plan), 'Второй пробный период недоступен');
        $this->expectException(\DomainException::class);
        $service->startTrial($fresh, $plan);
    }

    public function testPlanWithoutTrialDaysHasNoTrial(): void
    {
        $this->assertFalse($this->c->get(SubscriptionService::class)->canStartTrial($this->fx->user(), $this->fx->plan(['trial_days' => 0])));
    }

    public function testPayingDuringTrialStartsPaidPeriodImmediately(): void
    {
        $user = $this->fx->user();
        $plan = $this->fx->plan(['trial_days' => 7]);
        $trial = $this->c->get(SubscriptionService::class)->startTrial($user, $plan);

        $sub = $this->pay($user, $plan);

        $this->assertSame($trial->id, $sub->id);
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertGreaterThan(25, (int) (new DateTimeImmutable())->diff($sub->currentPeriodEnd)->days);
    }

    // ---- автопродление ---------------------------------------------------------------------

    public function testRenewalChargesSavedMethodAndExtendsPeriod(): void
    {
        $user = $this->fx->user();
        $plan = $this->fx->plan(['price' => 30000]);
        $sub = $this->pay($user, $plan);
        $this->db->update('subscriptions', ['next_attempt_at' => new DateTimeImmutable('-1 minute')], ['id' => $sub->id]);

        $result = $this->c->get(RenewalService::class)->renewDue();

        $this->assertSame(['charged' => 1, 'failed' => 0], $result);
        $request = end($this->gateway->requests);
        $this->assertSame(30000, $request->amount);
        $this->assertSame($sub->paymentMethodId, $request->paymentMethodId, 'Списание идёт по сохранённому способу оплаты');
        $this->assertNull($request->confirmation, 'Без участия пользователя');

        $renewed = $this->subs()->find($sub->id);
        $this->assertSame(SubscriptionStatus::Active, $renewed->status);
        $this->assertSame(PlanPeriod::Month->addTo($sub->currentPeriodEnd)->format('Y-m-d H:i'), $renewed->currentPeriodEnd->format('Y-m-d H:i'));
        $this->assertSame(0, $renewed->renewAttempts);
    }

    public function testRenewalIsNotRepeatedForTheSamePeriod(): void
    {
        $sub = $this->pay($this->fx->user(), $this->fx->plan());
        $this->db->update('subscriptions', ['next_attempt_at' => new DateTimeImmutable('-1 minute')], ['id' => $sub->id]);
        $service = $this->c->get(RenewalService::class);

        $service->renewDue();
        $second = $service->renewDue();

        $this->assertSame(['charged' => 0, 'failed' => 0], $second);
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT count(*) FROM payments'));
    }

    public function testFailedRenewalMovesToGracePeriodAndKeepsAccess(): void
    {
        $user = $this->fx->user();
        $sub = $this->pay($user, $this->fx->plan());
        $this->db->update('subscriptions', ['next_attempt_at' => new DateTimeImmutable('-1 minute')], ['id' => $sub->id]);
        $this->gateway->failNext = new PaymentGatewayException('Недостаточно средств', 400, 'insufficient_funds');

        $result = $this->c->get(RenewalService::class)->renewDue();

        $this->assertSame(['charged' => 0, 'failed' => 1], $result);
        $failed = $this->subs()->find($sub->id);
        $this->assertSame(SubscriptionStatus::PastDue, $failed->status);
        $this->assertSame(1, $failed->renewAttempts);
        $this->assertNotNull($failed->graceUntil);
        $this->assertGreaterThan($sub->currentPeriodEnd, $failed->graceUntil);
        $this->assertTrue($failed->status->isLive(), 'В льготный период доступ сохраняется');
        $this->assertNotNull($failed->nextAttemptAt, 'Будет повторная попытка');
        $this->runQueue();
        $this->assertNotEmpty(array_filter($this->mail->to($user->email), static fn ($m): bool => str_contains($m->subject, 'продлить')));
    }

    public function testCanceledSubscriptionKeepsAccessButIsNotRenewed(): void
    {
        $user = $this->fx->user();
        $sub = $this->pay($user, $this->fx->plan());
        $this->c->get(SubscriptionService::class)->cancel($sub, 'дорого');
        $this->db->update('subscriptions', ['next_attempt_at' => new DateTimeImmutable('-1 minute')], ['id' => $sub->id]);

        $result = $this->c->get(RenewalService::class)->renewDue();

        $this->assertSame(['charged' => 0, 'failed' => 0], $result);
        $canceled = $this->subs()->find($sub->id);
        $this->assertFalse($canceled->autoRenew);
        $this->assertTrue($canceled->grantsAccess(), 'Оплаченный период дочитывается');
    }

    public function testExpiredSubscriptionsAreClosedByExpiryJob(): void
    {
        $sub = $this->pay($this->fx->user(), $this->fx->plan(), '', false);
        $this->db->update('subscriptions', ['current_period_end' => new DateTimeImmutable('-1 hour')], ['id' => $sub->id]);

        $closed = $this->c->get(RenewalService::class)->expireDue();

        $this->assertSame(1, $closed);
        $this->assertSame(SubscriptionStatus::Expired, $this->subs()->find($sub->id)->status);
    }

    // ---- возвраты ---------------------------------------------------------------------------

    public function testFullRefundRevokesAccess(): void
    {
        $user = $this->fx->user();
        $admin = $this->fx->admin();
        $sub = $this->pay($user, $this->fx->plan(['price' => 30000]));
        $payment = $this->payments()->find((int) $this->db->fetchValue('SELECT id FROM payments LIMIT 1'));

        $this->c->get(RefundService::class)->refund($payment, null, 'по просьбе клиента', $admin);

        $refunded = $this->payments()->find($payment->id);
        $this->assertSame(30000, $refunded->refundedAmount);
        $this->assertCount(1, $this->gateway->refunds);
        $this->assertSame(SubscriptionStatus::Expired, $this->subs()->find($sub->id)->status);
    }

    public function testPartialRefundKeepsAccess(): void
    {
        $user = $this->fx->user();
        $admin = $this->fx->admin();
        $sub = $this->pay($user, $this->fx->plan(['price' => 30000]));
        $payment = $this->payments()->find((int) $this->db->fetchValue('SELECT id FROM payments LIMIT 1'));

        $this->c->get(RefundService::class)->refund($payment, 10000, 'частично', $admin);

        $this->assertSame(10000, $this->payments()->find($payment->id)->refundedAmount);
        $this->assertSame(SubscriptionStatus::Active, $this->subs()->find($sub->id)->status);
    }

    public function testRefundCannotExceedPaidAmount(): void
    {
        $admin = $this->fx->admin();
        $this->pay($this->fx->user(), $this->fx->plan(['price' => 30000]));
        $payment = $this->payments()->find((int) $this->db->fetchValue('SELECT id FROM payments LIMIT 1'));
        $service = $this->c->get(RefundService::class);

        $service->refund($payment, 20000, 'часть', $admin);
        $fresh = $this->payments()->find($payment->id);

        $this->expectException(\DomainException::class);
        $service->refund($fresh, 20000, 'ещё часть', $admin);
    }

    public function testUnpaidPaymentCannotBeRefunded(): void
    {
        $payment = $this->startPayment($this->fx->user(), $this->fx->plan());

        $this->expectException(\DomainException::class);
        $this->c->get(RefundService::class)->refund($payment, null, 'нельзя', $this->fx->admin());
    }

    public function testRefundMadeInYooKassaCabinetArrivesViaWebhook(): void
    {
        $sub = $this->pay($this->fx->user(), $this->fx->plan(['price' => 30000]));
        $payment = $this->payments()->find((int) $this->db->fetchValue('SELECT id FROM payments LIMIT 1'));
        $this->gateway->refunds['ext-ref-1'] = [
            'id' => 'ext-ref-1', 'payment_id' => $payment->yookassaId, 'status' => 'succeeded',
            'amount' => ['value' => '300.00', 'currency' => 'RUB'],
        ];

        $this->webhook('refund.succeeded', 'ext-ref-1');
        $this->webhook('refund.succeeded', 'ext-ref-1'); // повтор

        $this->assertSame(30000, $this->payments()->find($payment->id)->refundedAmount);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM refunds'));
        $this->assertSame(SubscriptionStatus::Expired, $this->subs()->find($sub->id)->status);
    }

    // ---- подарки ---------------------------------------------------------------------------

    public function testGiftPurchaseAndRedemption(): void
    {
        $buyer = $this->fx->user(['email' => 'buyer@example.test']);
        $friend = $this->fx->user(['email' => 'friend@example.test']);
        $plan = $this->fx->plan(['period' => 'year', 'price' => 249000]);

        $result = $this->checkout()->startGift($buyer, $plan, 'friend@example.test', 'Друг', 'С днём рождения!', '', 'redirect');
        $payment = $result->payment;
        $this->gateway->succeed((string) $this->payments()->find($payment->id)->yookassaId);
        $this->webhook('payment.succeeded', (string) $this->payments()->find($payment->id)->yookassaId);

        $this->assertNull($this->subs()->findLiveForUser($buyer->id), 'Покупатель подписку не получает');
        $giftMail = $this->mail->to('friend@example.test');
        $this->assertNotEmpty($giftMail, 'Получателю уходит письмо с кодом');
        $code = (string) $this->db->fetchValue('SELECT code FROM gifts LIMIT 1');
        $this->assertStringContainsString($code, $giftMail[0]->html . $giftMail[0]->text);

        $service = $this->c->get(\App\Billing\GiftService::class);
        $sub = $service->redeem($friend, strtolower($code));
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame($friend->id, $sub->userId);
        $this->assertSame('gift', $sub->source);

        // подарок одноразовый
        try {
            $service->redeem($this->fx->user(), $code);
            $this->fail('Повторная активация должна быть отклонена');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('уже активирован', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM subscriptions'));
    }

    public function testUnpaidGiftCannotBeRedeemed(): void
    {
        $buyer = $this->fx->user();
        $this->checkout()->startGift($buyer, $this->fx->plan(), 'friend@example.test', 'Друг', '', '', 'redirect');
        $code = (string) $this->db->fetchValue('SELECT code FROM gifts LIMIT 1');

        $this->expectException(\DomainException::class);
        $this->c->get(\App\Billing\GiftService::class)->redeem($this->fx->user(), $code);
    }

    public function testUnknownGiftCodeIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->c->get(\App\Billing\GiftService::class)->redeem($this->fx->user(), 'NOPE-NOPE-NOPE');
    }

    // ---- ручная выдача администратором -------------------------------------------------------

    public function testManualGrantAndRevoke(): void
    {
        $user = $this->fx->user();
        $admin = $this->fx->admin();
        $plan = $this->fx->plan(['tier' => 'premium']);
        $service = $this->c->get(SubscriptionService::class);

        $sub = $service->grantManual($user, $plan, new DateTimeImmutable('+90 days'), $admin);
        $this->assertSame('manual', $sub->source);
        $this->assertTrue($sub->grantsAccess());

        $service->revoke($sub, $admin);
        $this->assertFalse($this->subs()->find($sub->id)->grantsAccess());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM audit_log WHERE action = 'subscription.revoked'"));
    }

    public function testManualGrantRequiresFutureDate(): void
    {
        $this->expectException(\DomainException::class);
        $this->c->get(SubscriptionService::class)->grantManual($this->fx->user(), $this->fx->plan(), new DateTimeImmutable('-1 day'), $this->fx->admin());
    }
}
