<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLog;
use App\Billing\Gateway\GatewayPayment;
use App\Billing\Gateway\PaymentGateway;
use App\Core\Config;
use App\Core\Database\Database;
use App\Core\Logger;
use App\Notification\MailService;
use App\Settings\SettingsService;
use App\User\UserRepository;
use DateTimeImmutable;

/**
 * Единственное место, где состояние платежа в ЮKassa превращается в состояние у нас.
 * Вызывается из вебхуков, со страницы возврата после оплаты, из сверки и автопродления.
 * Состояние платежа всегда перечитывается из API ЮKassa — тело вебхука не считается доверенным.
 */
final class PaymentProcessor
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentRepository $payments,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionRepository $subs,
        private readonly PromoCodeService $promos,
        private readonly GiftRepository $gifts,
        private readonly RefundService $refunds,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly SettingsService $settings,
        private readonly Database $db,
        private readonly Logger $logger,
        private readonly AuditLog $audit,
        private readonly Config $config,
    ) {
    }

    /**
     * Обработать уведомление ЮKassa. Возвращает 'processed' | 'ignored'.
     *
     * @param array<string,mixed> $event
     */
    public function handleWebhook(array $event): string
    {
        $type = (string) ($event['event'] ?? '');
        $objectId = (string) ($event['object']['id'] ?? '');
        if ($objectId === '') {
            return 'ignored';
        }

        if (str_starts_with($type, 'payment.')) {
            $payment = $this->payments->findByYookassaId($objectId);
            if ($payment === null) {
                $this->logger->warning('Вебхук для неизвестного платежа', ['id' => $objectId, 'event' => $type]);

                return 'ignored';
            }
            $this->sync($payment);

            return 'processed';
        }
        if ($type === 'refund.succeeded') {
            // подтверждаем возврат у ЮKassa, а не по телу запроса
            $this->refunds->handleRemoteRefund($this->gateway->getRefund($objectId));

            return 'processed';
        }

        return 'ignored';
    }

    /** Перечитать платёж в ЮKassa и применить. */
    public function sync(Payment $payment): Payment
    {
        if ($payment->yookassaId === null) {
            return $payment;
        }
        $remote = $this->gateway->getPayment($payment->yookassaId);
        $this->apply($payment, $remote);

        return $this->payments->find($payment->id) ?? $payment;
    }

    public function apply(Payment $local, GatewayPayment $remote): void
    {
        $this->db->transaction(function () use ($local, $remote): void {
            $payment = $this->payments->lockForUpdate($local->id);
            if ($payment === null) {
                return;
            }
            if ($payment->amount !== $remote->amount) {
                $this->logger->critical('Сумма платежа не совпадает с ЮKassa', ['payment' => $payment->id, 'local' => $payment->amount, 'remote' => $remote->amount]);

                return;
            }
            if ($payment->status === PaymentStatus::Succeeded) {
                // успех необратим; обновляем только сумму возвратов, если она пришла из ЮKassa
                if ($remote->refundedAmount > $payment->refundedAmount) {
                    $this->logger->info('Возврат, оформленный в ЮKassa, будет применён вебхуком refund.succeeded', ['payment' => $payment->id]);
                }

                return;
            }
            if ($payment->status === PaymentStatus::Canceled && !$remote->isSucceeded()) {
                return;
            }

            if ($remote->isSucceeded()) {
                $this->onSucceeded($payment, $remote);
            } elseif ($remote->isCanceled()) {
                $this->onCanceled($payment, $remote);
            } else {
                $this->payments->update($payment->id, [
                    'status' => PaymentStatus::tryFrom($remote->status)?->value ?? PaymentStatus::Pending->value,
                    'confirmation_url' => $remote->confirmationUrl ?? $payment->confirmationUrl,
                ]);
            }
        });
    }

    private function onSucceeded(Payment $payment, GatewayPayment $remote): void
    {
        $this->payments->update($payment->id, [
            'status' => PaymentStatus::Succeeded->value,
            'paid_at' => new DateTimeImmutable(),
            'payment_method_id' => $remote->paymentMethodId,
            'payment_method_title' => $remote->paymentMethodTitle,
        ]);
        $fresh = $this->payments->find($payment->id) ?? $payment;
        $user = $fresh->userId !== null ? $this->users->find($fresh->userId) : null;

        $subscription = null;
        if ($fresh->kind === PaymentKind::Gift) {
            $this->onGiftPaid($fresh);
        } else {
            $subscription = $this->subscriptions->applyPayment($fresh, $remote);
        }
        $this->promos->redeem($fresh);
        $this->audit->record('payment.succeeded', $fresh->userId, 'payment', $fresh->id, ['amount' => $fresh->amount]);

        if ($user !== null) {
            $this->mail->queue($user->email, 'Оплата прошла успешно', 'payment-success', [
                'name' => $user->firstName(),
                'amount' => $fresh->amount,
                'description' => $fresh->description,
                'paymentId' => $fresh->id,
                'until' => $subscription?->currentPeriodEnd,
                'autoRenew' => $subscription?->willRenew() ?? false,
                'card' => $fresh->paymentMethodTitle,
                'isGift' => $fresh->kind === PaymentKind::Gift,
                'url' => $this->config->appUrl() . '/account/payments',
            ], $user);
        }
    }

    private function onCanceled(Payment $payment, GatewayPayment $remote): void
    {
        $this->payments->update($payment->id, [
            'status' => PaymentStatus::Canceled->value,
            'cancel_reason' => $remote->cancellationReason,
        ]);
        $this->audit->record('payment.canceled', $payment->userId, 'payment', $payment->id, ['reason' => $remote->cancellationReason]);

        if ($payment->kind === PaymentKind::Gift) {
            $gift = $this->gifts->findByPayment($payment->id);
            if ($gift !== null && $gift->status === 'pending') {
                $this->gifts->update($gift->id, ['status' => 'expired']);
            }

            return;
        }
        if ($payment->kind === PaymentKind::Renewal && $payment->subscriptionId !== null) {
            $sub = $this->subs->find($payment->subscriptionId);
            if ($sub !== null && $sub->status->isLive()) {
                $this->subscriptions->registerRenewalFailure($sub, (string) $remote->cancellationReason);
            }
        }
    }

    private function onGiftPaid(Payment $payment): void
    {
        $gift = $this->gifts->findByPayment($payment->id);
        if ($gift === null || $gift->status !== 'pending') {
            return;
        }
        $days = max(30, $this->settings->int('billing.gift_valid_days', 365));
        $this->gifts->update($gift->id, ['status' => 'paid', 'expires_at' => new DateTimeImmutable('+' . $days . ' days')]);
        $gift = $this->gifts->find($gift->id) ?? $gift;
        $redeemUrl = $this->config->appUrl() . '/gift/redeem?code=' . rawurlencode($gift->code);

        $this->mail->queue($gift->recipientEmail, 'Вам подарили подписку', 'gift-received', [
            'recipient' => $gift->recipientName,
            'from' => $gift->buyerName,
            'plan' => $gift->planName,
            'message' => $gift->message,
            'code' => $gift->code,
            'url' => $redeemUrl,
            'expires' => $gift->expiresAt,
        ]);
        $this->gifts->update($gift->id, ['delivered_at' => new DateTimeImmutable()]);

        $buyer = $payment->userId !== null ? $this->users->find($payment->userId) : null;
        if ($buyer !== null) {
            $this->mail->queue($buyer->email, 'Подарочная подписка оплачена', 'gift-purchased', [
                'name' => $buyer->firstName(),
                'recipientEmail' => $gift->recipientEmail,
                'plan' => $gift->planName,
                'code' => $gift->code,
            ], $buyer);
        }
    }

    /**
     * Сверка с ЮKassa: пересинхронизировать зависшие платежи. Возвращает количество изменений.
     *
     * @param list<Payment> $payments
     * @return array{checked:int,fixed:int,errors:int}
     */
    public function reconcile(array $payments): array
    {
        $result = ['checked' => 0, 'fixed' => 0, 'errors' => 0];
        foreach ($payments as $payment) {
            ++$result['checked'];
            try {
                $before = $payment->status;
                $after = $this->sync($payment)->status;
                if ($before !== $after) {
                    ++$result['fixed'];
                }
            } catch (\Throwable $e) {
                ++$result['errors'];
                $this->logger->error('Сверка платежа не удалась', ['payment' => $payment->id, 'exception' => $e]);
            }
        }

        return $result;
    }
}
