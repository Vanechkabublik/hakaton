<?php

declare(strict_types=1);

namespace App\Billing;

use App\Article\AccessLevel;
use App\Audit\AuditLog;
use App\Billing\Gateway\GatewayPayment;
use App\Core\Config;
use App\Core\Database\Database;
use App\Notification\MailService;
use App\Settings\SettingsService;
use App\User\User;
use App\User\UserRepository;
use DateTimeImmutable;

/**
 * Жизненный цикл подписок: выдача доступа после оплаты, продления, смена тарифа, отмена, истечение.
 * Доступ определяется датой окончания периода: отмена лишь отключает автопродление.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subs,
        private readonly PlanRepository $plans,
        private readonly PaymentRepository $payments,
        private readonly UserRepository $users,
        private readonly Database $db,
        private readonly MailService $mail,
        private readonly AuditLog $audit,
        private readonly Config $config,
        private readonly SettingsService $settings,
    ) {
    }

    // ---- чтение --------------------------------------------------------------------------

    /** Подписка, дающая доступ прямо сейчас. */
    public function currentFor(?User $user): ?Subscription
    {
        return $user === null ? null : $this->subs->findAccessFor($user->id);
    }

    /** Подписка пользователя в любом «живом» статусе (в т.ч. с просроченной оплатой). */
    public function liveFor(User $user): ?Subscription
    {
        return $this->subs->findLiveForUser($user->id);
    }

    public function tierFor(?User $user): ?PlanTier
    {
        return $this->currentFor($user)?->tier();
    }

    /** Открывает ли подписка пользователя материал с заданным уровнем доступа. */
    public function allows(?User $user, AccessLevel $level): bool
    {
        if ($level === AccessLevel::Free) {
            return true;
        }
        $tier = $this->tierFor($user);
        if ($tier === null) {
            return false;
        }

        return $level === AccessLevel::Premium ? $tier->covers(PlanTier::Premium) : true;
    }

    public function canStartTrial(User $user, Plan $plan): bool
    {
        return $plan->trialDays > 0
            && $user->trialUsedAt === null
            && $this->subs->latestForUser($user->id) === null;
    }

    // ---- выдача доступа по платежу ----------------------------------------------------------

    /** Применить успешный платёж к подписке. Вызывать внутри транзакции. */
    public function applyPayment(Payment $payment, GatewayPayment $remote): ?Subscription
    {
        $plan = $payment->planId !== null ? $this->plans->find($payment->planId) : null;
        if ($plan === null || $payment->userId === null) {
            return null;
        }
        $user = $this->users->find($payment->userId);
        if ($user === null) {
            return null;
        }
        $now = new DateTimeImmutable();
        $saveMethod = $payment->saveMethod && $remote->paymentMethodSaved && $remote->paymentMethodId !== null;
        $live = $this->subs->findLiveForUser($user->id);

        switch ($payment->kind) {
            case PaymentKind::Renewal:
                $sub = $payment->subscriptionId !== null ? $this->subs->find($payment->subscriptionId) : $live;
                if ($sub === null) {
                    return null;
                }
                $from = $sub->currentPeriodEnd >= $now->modify('-7 days') ? $sub->currentPeriodEnd : $now;
                $end = $plan->period->addTo($from);
                $this->subs->update($sub->id, [
                    'plan_id' => $plan->id,
                    'pending_plan_id' => null,
                    'status' => SubscriptionStatus::Active->value,
                    'current_period_start' => $from,
                    'current_period_end' => $end,
                    'grace_until' => null,
                    'renew_attempts' => 0,
                    'next_attempt_at' => $end,
                ]);
                $id = $sub->id;
                break;

            case PaymentKind::Upgrade:
                if ($live === null) {
                    $id = $this->createSubscription($user, $plan, $payment, $saveMethod ? $remote : null, $now);
                    break;
                }
                $end = $plan->period->addTo($now);
                $this->subs->update($live->id, [
                    'plan_id' => $plan->id,
                    'pending_plan_id' => null,
                    'status' => SubscriptionStatus::Active->value,
                    'source' => 'payment',
                    'current_period_start' => $now,
                    'current_period_end' => $end,
                    'grace_until' => null,
                    'renew_attempts' => 0,
                    'next_attempt_at' => $live->autoRenew ? $end : null,
                ] + $this->methodFields($saveMethod ? $remote : null));
                $id = $live->id;
                break;

            case PaymentKind::Extension:
            case PaymentKind::Initial:
            default:
                if ($live === null) {
                    $id = $this->createSubscription($user, $plan, $payment, $saveMethod ? $remote : null, $now);
                    break;
                }
                if ($live->isTrial()) {
                    // переход с пробного периода на платный: платный период начинается сразу
                    $end = $plan->period->addTo($now);
                    $this->subs->update($live->id, [
                        'plan_id' => $plan->id,
                        'status' => SubscriptionStatus::Active->value,
                        'source' => 'payment',
                        'current_period_start' => $now,
                        'current_period_end' => $end,
                        'next_attempt_at' => $saveMethod ? $end : null,
                    ] + $this->methodFields($saveMethod ? $remote : null));
                } else {
                    $from = $live->currentPeriodEnd > $now ? $live->currentPeriodEnd : $now;
                    $end = $plan->period->addTo($from);
                    $this->subs->update($live->id, [
                        'plan_id' => $plan->id,
                        'status' => SubscriptionStatus::Active->value,
                        'current_period_end' => $end,
                        'grace_until' => null,
                        'renew_attempts' => 0,
                        'next_attempt_at' => ($live->autoRenew || $saveMethod) ? $end : null,
                    ] + $this->methodFields($saveMethod ? $remote : null));
                }
                $id = $live->id;
                break;
        }

        $this->db->update('payments', ['subscription_id' => $id], ['id' => $payment->id]);
        $sub = $this->subs->find($id);
        if ($sub !== null) {
            $this->audit->record('subscription.paid', $user->id, 'subscription', $sub->id, ['payment' => $payment->id, 'kind' => $payment->kind->value]);
        }

        return $sub;
    }

    private function createSubscription(User $user, Plan $plan, Payment $payment, ?GatewayPayment $method, DateTimeImmutable $now): int
    {
        $end = $plan->period->addTo($now);

        return (int) $this->subs->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
            'source' => 'payment',
            'started_at' => $now,
            'current_period_start' => $now,
            'current_period_end' => $end,
            'auto_renew' => $method !== null,
            'payment_method_id' => $method?->paymentMethodId,
            'payment_method_title' => $method?->paymentMethodTitle,
            'next_attempt_at' => $method !== null ? $end : null,
            'paywall_article_id' => $payment->paywallArticleId,
        ]);
    }

    /** @return array<string,mixed> */
    private function methodFields(?GatewayPayment $remote): array
    {
        if ($remote === null) {
            return [];
        }

        return [
            'auto_renew' => true,
            'canceled_at' => null,
            'cancel_reason' => null,
            'payment_method_id' => $remote->paymentMethodId,
            'payment_method_title' => $remote->paymentMethodTitle,
        ];
    }

    // ---- пробный период, ручная выдача, подарки ---------------------------------------------

    public function startTrial(User $user, Plan $plan): Subscription
    {
        if (!$this->canStartTrial($user, $plan)) {
            throw new \DomainException('Пробный период недоступен');
        }
        $now = new DateTimeImmutable();
        $id = $this->db->transaction(function () use ($user, $plan, $now): int {
            $id = (int) $this->subs->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing->value,
                'source' => 'trial',
                'started_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $now->modify('+' . $plan->trialDays . ' days'),
                'auto_renew' => false,
            ]);
            $this->users->update($user->id, ['trial_used_at' => $now]);

            return $id;
        });
        $this->audit->record('subscription.trial', $user->id, 'subscription', $id);
        $sub = $this->subs->find($id) ?? throw new \RuntimeException('Подписка не создана');
        $this->mail->queue($user->email, 'Пробный период начался', 'trial-started', [
            'name' => $user->firstName(), 'plan' => $plan->title(), 'days' => $plan->trialDays,
            'until' => $sub->currentPeriodEnd, 'url' => $this->config->appUrl() . '/subscribe',
        ], $user);

        return $sub;
    }

    /** Ручная выдача подписки администратором до указанной даты. */
    public function grantManual(User $user, Plan $plan, DateTimeImmutable $until, User $admin): Subscription
    {
        $now = new DateTimeImmutable();
        if ($until <= $now) {
            throw new \DomainException('Дата окончания должна быть в будущем');
        }
        $live = $this->subs->findLiveForUser($user->id);
        if ($live !== null) {
            $this->subs->update($live->id, [
                'plan_id' => $plan->id,
                'pending_plan_id' => null,
                'status' => SubscriptionStatus::Active->value,
                'source' => 'manual',
                'current_period_end' => $until,
                'grace_until' => null,
                'auto_renew' => false,
                'canceled_at' => null,
                'next_attempt_at' => null,
                'granted_by' => $admin->id,
            ]);
            $id = $live->id;
        } else {
            $id = (int) $this->subs->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active->value,
                'source' => 'manual',
                'started_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $until,
                'granted_by' => $admin->id,
            ]);
        }
        $this->audit->record('subscription.granted', $admin->id, 'user', $user->id, ['plan' => $plan->code, 'until' => $until->format('Y-m-d')]);

        return $this->subs->find($id) ?? throw new \RuntimeException('Подписка не создана');
    }

    public function redeemGift(User $user, Gift $gift): Subscription
    {
        $plan = $this->plans->find($gift->planId) ?? throw new \DomainException('Тариф подарка недоступен');
        $now = new DateTimeImmutable();
        $live = $this->subs->findLiveForUser($user->id);
        if ($live === null) {
            $id = (int) $this->subs->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active->value,
                'source' => 'gift',
                'started_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $plan->period->addTo($now),
            ]);
        } else {
            $current = $this->plans->find($live->planId);
            $from = $live->currentPeriodEnd > $now ? $live->currentPeriodEnd : $now;
            $changes = [
                'status' => SubscriptionStatus::Active->value,
                'current_period_end' => $plan->period->addTo($from),
                'grace_until' => null,
                'next_attempt_at' => $live->autoRenew ? $plan->period->addTo($from) : null,
            ];
            if ($current === null || $plan->tier->rank() >= $current->tier->rank()) {
                $changes['plan_id'] = $plan->id;
            }
            $this->subs->update($live->id, $changes);
            $id = $live->id;
        }
        $this->audit->record('subscription.gift_redeemed', $user->id, 'subscription', $id, ['gift' => $gift->id]);

        return $this->subs->find($id) ?? throw new \RuntimeException('Подписка не найдена');
    }

    // ---- управление пользователем ------------------------------------------------------------

    /** Отключить автопродление; доступ сохраняется до конца оплаченного периода. */
    public function cancel(Subscription $sub, ?string $reason = null, ?User $by = null): void
    {
        $this->subs->update($sub->id, [
            'auto_renew' => false,
            'canceled_at' => new DateTimeImmutable(),
            'cancel_reason' => $reason !== null ? mb_substr($reason, 0, 300) : null,
            'next_attempt_at' => null,
            'pending_plan_id' => null,
        ]);
        $this->audit->record('subscription.canceled', $by?->id ?? $sub->userId, 'subscription', $sub->id);
    }

    public function setAutoRenew(Subscription $sub, bool $enabled): void
    {
        if ($enabled) {
            if ($sub->paymentMethodId === null) {
                throw new \DomainException('Нет сохранённого способа оплаты. Продлите подписку картой и отметьте автопродление.');
            }
            $this->subs->update($sub->id, [
                'auto_renew' => true,
                'canceled_at' => null,
                'cancel_reason' => null,
                'next_attempt_at' => $sub->currentPeriodEnd,
            ]);
        } else {
            $this->subs->update($sub->id, ['auto_renew' => false, 'next_attempt_at' => null, 'canceled_at' => new DateTimeImmutable()]);
        }
        $this->audit->record($enabled ? 'subscription.autorenew_on' : 'subscription.autorenew_off', $sub->userId, 'subscription', $sub->id);
    }

    /** Отвязать сохранённый способ оплаты. */
    public function forgetPaymentMethod(Subscription $sub): void
    {
        $this->subs->update($sub->id, [
            'payment_method_id' => null,
            'payment_method_title' => null,
            'auto_renew' => false,
            'next_attempt_at' => null,
        ]);
        $this->audit->record('subscription.method_removed', $sub->userId, 'subscription', $sub->id);
    }

    /** Понижение тарифа вступает в силу со следующего периода. */
    public function scheduleChange(Subscription $sub, Plan $plan): void
    {
        $this->subs->update($sub->id, ['pending_plan_id' => $plan->id]);
        $this->audit->record('subscription.change_scheduled', $sub->userId, 'subscription', $sub->id, ['plan' => $plan->code]);
    }

    public function clearScheduledChange(Subscription $sub): void
    {
        $this->subs->update($sub->id, ['pending_plan_id' => null]);
    }

    // ---- завершение ------------------------------------------------------------------------

    public function expire(Subscription $sub, string $reason = 'period_end', bool $notify = true): void
    {
        $this->subs->update($sub->id, [
            'status' => SubscriptionStatus::Expired->value,
            'ended_at' => new DateTimeImmutable(),
            'auto_renew' => false,
            'next_attempt_at' => null,
            'grace_until' => null,
        ]);
        $this->audit->record('subscription.expired', null, 'subscription', $sub->id, ['reason' => $reason]);
        if ($notify && $reason !== 'revoked') {
            $user = $this->users->find($sub->userId);
            if ($user !== null) {
                $this->mail->queue($user->email, 'Подписка закончилась', 'subscription-expired', [
                    'name' => $user->firstName(), 'plan' => $sub->planName, 'url' => $this->config->appUrl() . '/subscribe',
                    'wasTrial' => $sub->isTrial(),
                ], $user);
            }
        }
    }

    public function revoke(Subscription $sub, User $admin): void
    {
        $this->expire($sub, 'revoked', false);
        $this->audit->record('subscription.revoked', $admin->id, 'user', $sub->userId, ['subscription' => $sub->id]);
    }

    /** Полный возврат: доступ, оплаченный этим платежом, закрывается. */
    public function revokeForRefund(Payment $payment): void
    {
        if ($payment->userId === null) {
            return;
        }
        $sub = $payment->subscriptionId !== null ? $this->subs->find($payment->subscriptionId) : $this->subs->findLiveForUser($payment->userId);
        if ($sub !== null && $sub->status->isLive()) {
            $this->expire($sub, 'refund', true);
        }
    }

    // ---- неудачное автопродление ---------------------------------------------------------------

    /**
     * Списание не прошло: подписка переходит в «ожидает оплаты» (доступ сохраняется в льготный период),
     * дальше — повторные попытки; после исчерпания попыток подписка истекает по окончании льготного периода.
     */
    public function registerRenewalFailure(Subscription $sub, string $reason): void
    {
        $attempts = $sub->renewAttempts + 1;
        $max = max(1, $this->settings->int('billing.max_renew_attempts', 3));
        $graceDays = max(1, $this->settings->int('billing.grace_days', 3));
        $now = new DateTimeImmutable();
        $graceUntil = $sub->graceUntil ?? $sub->currentPeriodEnd->modify('+' . $graceDays . ' days');
        $retryDelayHours = [24, 48][$attempts - 1] ?? 24;

        $this->subs->update($sub->id, [
            'status' => SubscriptionStatus::PastDue->value,
            'renew_attempts' => $attempts,
            'grace_until' => $graceUntil,
            'next_attempt_at' => $attempts >= $max || $now->modify('+' . $retryDelayHours . ' hours') >= $graceUntil
                ? null
                : $now->modify('+' . $retryDelayHours . ' hours'),
        ]);
        $this->audit->record('subscription.renew_failed', null, 'subscription', $sub->id, ['attempt' => $attempts, 'reason' => $reason]);

        $user = $this->users->find($sub->userId);
        if ($user !== null) {
            $this->mail->queue($user->email, 'Не удалось продлить подписку', 'payment-failed', [
                'name' => $user->firstName(),
                'plan' => $sub->planName,
                'graceUntil' => $graceUntil,
                'url' => $this->config->appUrl() . '/account/subscription',
                'last' => $attempts >= $max,
            ], $user);
        }
    }

    // ---- расчёты ---------------------------------------------------------------------------

    /**
     * Неиспользованный остаток текущей оплаченной подписки — засчитывается в стоимость нового тарифа.
     */
    public function prorationCredit(Subscription $sub): int
    {
        if ($sub->status !== SubscriptionStatus::Active || !in_array($sub->source, ['payment'], true)) {
            return 0;
        }
        $now = new DateTimeImmutable();
        $total = $sub->currentPeriodEnd->getTimestamp() - $sub->currentPeriodStart->getTimestamp();
        $remaining = $sub->currentPeriodEnd->getTimestamp() - $now->getTimestamp();
        if ($total <= 0 || $remaining <= 0) {
            return 0;
        }
        $paid = $this->payments->lastPaidAmountForSubscription($sub->id) ?? (int) $sub->planPrice;

        return (int) floor($paid * min(1.0, $remaining / $total));
    }
}
