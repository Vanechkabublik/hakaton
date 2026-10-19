<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLog;
use App\Billing\Gateway\GatewayRefund;
use App\Billing\Gateway\PaymentGateway;
use App\Billing\Gateway\PaymentGatewayException;
use App\Core\Database\Database;
use App\Core\Security\Crypto;
use App\Notification\MailService;
use App\User\User;
use App\User\UserRepository;

/** Возвраты (полные и частичные) через ЮKassa и применение их последствий к подпискам. */
final class RefundService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentRepository $payments,
        private readonly SubscriptionService $subscriptions,
        private readonly GiftRepository $gifts,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly Database $db,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Создать возврат. $amount — в копейках; null = вся оставшаяся сумма.
     *
     * @throws \DomainException
     * @throws PaymentGatewayException
     */
    public function refund(Payment $payment, ?int $amount, string $reason, User $admin): void
    {
        if (!$payment->isSucceeded() || $payment->yookassaId === null) {
            throw new \DomainException('Вернуть можно только оплаченный платёж');
        }
        $available = $payment->refundableAmount() - $this->pendingRefundAmount($payment->id);
        $amount ??= $available;
        if ($amount < 100 || $amount > $available) {
            throw new \DomainException('Сумма возврата должна быть от 1 ₽ до ' . number_format($available / 100, 2, ',', ' ') . ' ₽');
        }

        $refundId = (int) $this->db->insert('refunds', [
            'payment_id' => $payment->id,
            'amount' => $amount,
            'status' => 'pending',
            'reason' => mb_substr($reason, 0, 300),
            'created_by' => $admin->id,
        ]);
        try {
            $remote = $this->gateway->createRefund(
                $payment->yookassaId,
                $amount,
                $reason !== '' ? $reason : 'Возврат по подписке',
                'refund-' . $refundId . '-' . Crypto::token(4),
                $payment->customerEmail,
                $payment->description,
            );
        } catch (PaymentGatewayException $e) {
            $this->db->update('refunds', ['status' => 'canceled'], ['id' => $refundId]);
            throw $e;
        }
        $this->db->update('refunds', ['yookassa_id' => $remote->id, 'status' => $remote->status], ['id' => $refundId]);
        $this->audit->record('payment.refund', $admin->id, 'payment', $payment->id, ['amount' => $amount, 'reason' => $reason]);

        if ($remote->status === 'succeeded') {
            $this->applySucceeded($payment->id);
        }
    }

    /** Вебхук refund.succeeded (или ручная синхронизация). */
    public function handleRemoteRefund(GatewayRefund $remote): void
    {
        if ($remote->status !== 'succeeded') {
            return;
        }
        $payment = $this->payments->findByYookassaId($remote->paymentId);
        if ($payment === null) {
            return;
        }
        $row = $this->db->fetchOne('SELECT id FROM refunds WHERE yookassa_id = :y', ['y' => $remote->id]);
        if ($row === null) {
            // Возврат оформлен в личном кабинете ЮKassa
            $this->db->insert('refunds', ['payment_id' => $payment->id, 'yookassa_id' => $remote->id, 'amount' => $remote->amount, 'status' => 'succeeded', 'reason' => 'Оформлен в ЮKassa']);
        } else {
            $this->db->update('refunds', ['status' => 'succeeded'], ['id' => (int) $row['id']]);
        }
        $this->applySucceeded($payment->id);
    }

    /** Пересчитать сумму возвратов платежа и применить последствия (идемпотентно). */
    public function applySucceeded(int $paymentId): void
    {
        $this->db->transaction(function () use ($paymentId): void {
            $payment = $this->payments->lockForUpdate($paymentId);
            if ($payment === null) {
                return;
            }
            $refunded = (int) $this->db->fetchValue("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE payment_id = :p AND status = 'succeeded'", ['p' => $paymentId]);
            $wasFull = $payment->refundedAmount >= $payment->amount;
            if ($refunded === $payment->refundedAmount) {
                return;
            }
            $this->payments->update($paymentId, ['refunded_amount' => min($refunded, $payment->amount)]);
            $fullNow = $refunded >= $payment->amount;

            if ($fullNow && !$wasFull) {
                if ($payment->kind === PaymentKind::Gift) {
                    $gift = $this->gifts->findByPayment($paymentId);
                    if ($gift !== null && $gift->status === 'paid') {
                        $this->gifts->update($gift->id, ['status' => 'expired']);
                    }
                } else {
                    $this->subscriptions->revokeForRefund($payment);
                }
            }
            $user = $payment->userId !== null ? $this->users->find($payment->userId) : null;
            if ($user !== null) {
                $this->mail->queue($user->email, 'Возврат средств', 'refund', [
                    'name' => $user->firstName(),
                    'amount' => $refunded - $payment->refundedAmount,
                    'full' => $fullNow,
                    'description' => $payment->description,
                ], $user);
            }
        });
    }

    private function pendingRefundAmount(int $paymentId): int
    {
        return (int) $this->db->fetchValue("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE payment_id = :p AND status = 'pending'", ['p' => $paymentId]);
    }
}
