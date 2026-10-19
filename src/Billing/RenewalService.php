<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Gateway\PaymentGateway;
use App\Billing\Gateway\PaymentGatewayException;
use App\Billing\Gateway\PaymentRequest;
use App\Core\Config;
use App\Core\Logger;
use App\Notification\MailService;
use App\User\UserRepository;
use DateTimeImmutable;

/** Фоновые процессы подписок: автопродление, истечение, напоминания. Запускаются планировщиком. */
final class RenewalService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentRepository $payments,
        private readonly SubscriptionRepository $subs,
        private readonly PlanRepository $plans,
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentProcessor $processor,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    /** @return array{charged:int,failed:int} */
    public function renewDue(): array
    {
        $result = ['charged' => 0, 'failed' => 0];
        if (!$this->gateway->isConfigured()) {
            return $result;
        }
        foreach ($this->subs->dueForRenewal() as $sub) {
            try {
                $this->charge($sub) ? ++$result['charged'] : ++$result['failed'];
            } catch (\Throwable $e) {
                ++$result['failed'];
                $this->logger->error('Ошибка автопродления', ['subscription' => $sub->id, 'exception' => $e]);
            }
        }

        return $result;
    }

    /** Списание по сохранённому способу оплаты. true — запрос принят платёжной системой. */
    private function charge(Subscription $sub): bool
    {
        $plan = $this->plans->find($sub->pendingPlanId ?? $sub->planId);
        $user = $this->users->find($sub->userId);
        if ($plan === null || $user === null || $sub->paymentMethodId === null) {
            $this->subscriptions->registerRenewalFailure($sub, 'no_plan_or_method');

            return false;
        }

        $key = sprintf('renew-%d-%d-%d', $sub->id, $sub->currentPeriodEnd->getTimestamp(), $sub->renewAttempts);
        $payment = $this->findByIdempotenceKey($key);
        if ($payment === null) {
            $id = $this->payments->create([
                'user_id' => $user->id,
                'customer_email' => $user->email,
                'subscription_id' => $sub->id,
                'plan_id' => $plan->id,
                'kind' => PaymentKind::Renewal->value,
                'status' => PaymentStatus::Pending->value,
                'amount' => $plan->price,
                'original_amount' => $plan->price,
                'description' => $plan->receiptDescription(),
                'idempotence_key' => $key,
                'is_recurring' => true,
                'payment_method_id' => $sub->paymentMethodId,
                'payment_method_title' => $sub->paymentMethodTitle,
                'metadata' => ['plan' => $plan->code, 'kind' => 'renewal'],
            ]);
            $payment = $this->payments->find($id);
        }
        if ($payment === null) {
            return false;
        }
        if ($payment->yookassaId !== null) {
            $this->processor->sync($payment); // уже отправляли — просто уточняем статус

            return true;
        }

        // пока платёж в обработке, повторно не списываем
        $this->subs->update($sub->id, ['next_attempt_at' => null]);
        try {
            $remote = $this->gateway->createPayment(new PaymentRequest(
                $plan->price,
                $plan->receiptDescription() . ' — автопродление',
                null,
                null,
                false,
                $sub->paymentMethodId,
                ['payment_id' => (string) $payment->id, 'user_id' => (string) $user->id, 'kind' => 'renewal'],
                $user->email,
                $plan->receiptDescription(),
            ), $key);
        } catch (PaymentGatewayException $e) {
            if ($e->httpStatus === null || $e->httpStatus >= 500) {
                // сеть или сбой на стороне ЮKassa: повторим позже с тем же ключом
                $this->subs->update($sub->id, ['next_attempt_at' => new DateTimeImmutable('+15 minutes')]);

                return false;
            }
            $this->payments->update($payment->id, ['status' => PaymentStatus::Canceled->value, 'cancel_reason' => $e->errorCode ?? 'gateway_error']);
            $fresh = $this->subs->find($sub->id) ?? $sub;
            $this->subscriptions->registerRenewalFailure($fresh, $e->getMessage());

            return false;
        }
        $this->payments->update($payment->id, ['yookassa_id' => $remote->id]);
        $this->processor->apply($payment, $remote);

        return true;
    }

    private function findByIdempotenceKey(string $key): ?Payment
    {
        $id = $this->payments->idByIdempotenceKey($key);

        return $id !== null ? $this->payments->find($id) : null;
    }

    /** Закрыть подписки, у которых доступ закончился и продления не будет. */
    public function expireDue(): int
    {
        $count = 0;
        foreach ($this->subs->dueForExpiry() as $sub) {
            $this->subscriptions->expire($sub, $sub->status === SubscriptionStatus::PastDue ? 'payment_failed' : 'period_end');
            ++$count;
        }

        return $count;
    }

    /** Напоминания об окончании подписки и предстоящем списании. */
    public function sendReminders(): int
    {
        $sent = 0;
        foreach ($this->subs->endingWithin(73) as $sub) {
            $hours = ($sub->currentPeriodEnd->getTimestamp() - time()) / 3600;
            $user = $this->users->find($sub->userId);
            if ($user === null || !$user->isActive()) {
                continue;
            }
            if ($sub->willRenew()) {
                $kind = $hours <= 25 ? 'renew_1d' : null;
                $template = 'renewal-notice';
            } elseif ($sub->isTrial()) {
                $kind = $hours <= 25 ? 'trial_1d' : 'trial_3d';
                $template = 'trial-ending';
            } else {
                $kind = $hours <= 25 ? 'end_1d' : 'end_3d';
                $template = 'subscription-ending';
            }
            if ($kind === null || $this->subs->reminderSent($sub->id, $kind, $sub->currentPeriodEnd)) {
                continue;
            }
            $plan = $this->plans->find($sub->pendingPlanId ?? $sub->planId);
            $subject = match ($template) {
                'renewal-notice' => 'Завтра мы продлим вашу подписку',
                'trial-ending' => 'Пробный период скоро закончится',
                default => 'Подписка скоро закончится',
            };
            $this->mail->queue($user->email, $subject, $template, [
                'name' => $user->firstName(),
                'plan' => $plan?->title() ?? $sub->planName,
                'until' => $sub->currentPeriodEnd,
                'amount' => $plan?->price ?? 0,
                'card' => $sub->paymentMethodTitle,
                'days' => max(1, (int) ceil($hours / 24)),
                'url' => $this->config->appUrl() . ($template === 'renewal-notice' ? '/account/subscription' : '/subscribe'),
            ], $user);
            $this->subs->markReminderSent($sub->id, $kind, $sub->currentPeriodEnd);
            ++$sent;
        }

        return $sent;
    }
}
