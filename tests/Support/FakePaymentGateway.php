<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\Gateway\GatewayPayment;
use App\Billing\Gateway\GatewayRefund;
use App\Billing\Gateway\PaymentGateway;
use App\Billing\Gateway\PaymentGatewayException;
use App\Billing\Gateway\PaymentRequest;
use App\Billing\Money;

/** Платёжный шлюз в памяти: тест сам «оплачивает» или отменяет платежи, как это сделала бы ЮKassa. */
final class FakePaymentGateway implements PaymentGateway
{
    public bool $configured = true;

    /** @var array<string,array<string,mixed>> */
    public array $payments = [];

    /** @var array<string,array<string,mixed>> */
    public array $refunds = [];

    /** @var list<PaymentRequest> */
    public array $requests = [];

    /** Если задано — следующий createPayment() бросит это исключение (и сбросит поле). */
    public ?PaymentGatewayException $failNext = null;

    private int $sequence = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function createPayment(PaymentRequest $request, string $idempotenceKey): GatewayPayment
    {
        if ($this->failNext !== null) {
            $error = $this->failNext;
            $this->failNext = null;
            throw $error;
        }
        // Повтор с тем же ключом идемпотентности возвращает тот же платёж, как в реальном API
        foreach ($this->payments as $id => $payment) {
            if (($payment['_key'] ?? null) === $idempotenceKey) {
                return $this->getPayment((string) $id);
            }
        }
        $this->requests[] = $request;
        $id = 'fake-pay-' . (++$this->sequence);
        $recurring = $request->paymentMethodId !== null;
        $this->payments[$id] = [
            '_key' => $idempotenceKey,
            'id' => $id,
            'status' => $recurring ? 'succeeded' : 'pending',
            'paid' => $recurring,
            'amount' => ['value' => Money::toDecimal($request->amount), 'currency' => 'RUB'],
            'metadata' => $request->metadata,
            'test' => true,
            'confirmation' => $request->confirmation === 'embedded'
                ? ['type' => 'embedded', 'confirmation_token' => 'ct-' . $id]
                : ($recurring ? null : ['type' => 'redirect', 'confirmation_url' => 'https://yookassa.test/pay/' . $id]),
            'payment_method' => $recurring ? ['id' => $request->paymentMethodId, 'saved' => true, 'title' => 'Bank card *4444'] : null,
            'save_payment_method' => $request->savePaymentMethod,
        ];

        return $this->getPayment($id);
    }

    public function getPayment(string $id): GatewayPayment
    {
        if (!isset($this->payments[$id])) {
            throw new PaymentGatewayException("Платёж {$id} не найден", 404);
        }

        return GatewayPayment::fromArray($this->payments[$id]);
    }

    /** Покупатель оплатил: платёж становится succeeded (карта сохраняется, если об этом просили). */
    public function succeed(string $id, bool $saveMethod = true): void
    {
        $payment = &$this->payments[$id];
        $payment['status'] = 'succeeded';
        $payment['paid'] = true;
        $payment['payment_method'] = [
            'id' => 'pm-' . $id,
            'saved' => $saveMethod && ($payment['save_payment_method'] ?? false),
            'title' => 'Bank card *4444',
        ];
    }

    public function cancel(string $id, string $reason = 'expired_on_confirmation'): void
    {
        $this->payments[$id]['status'] = 'canceled';
        $this->payments[$id]['paid'] = false;
        $this->payments[$id]['cancellation_details'] = ['party' => 'yoo_money', 'reason' => $reason];
    }

    /** Подменить сумму в ответе API (проверка защиты от рассогласования сумм). */
    public function tamperAmount(string $id, int $kopecks): void
    {
        $this->payments[$id]['amount']['value'] = Money::toDecimal($kopecks);
    }

    public function createRefund(string $paymentId, int $amount, string $description, string $idempotenceKey, ?string $receiptEmail = null, string $receiptItem = ''): GatewayRefund
    {
        $id = 'fake-ref-' . (count($this->refunds) + 1);
        $this->refunds[$id] = [
            'id' => $id,
            'payment_id' => $paymentId,
            'status' => 'succeeded',
            'amount' => ['value' => Money::toDecimal($amount), 'currency' => 'RUB'],
        ];

        return GatewayRefund::fromArray($this->refunds[$id]);
    }

    public function getRefund(string $id): GatewayRefund
    {
        if (!isset($this->refunds[$id])) {
            throw new PaymentGatewayException("Возврат {$id} не найден", 404);
        }

        return GatewayRefund::fromArray($this->refunds[$id]);
    }

    public function testConnection(): array
    {
        return ['account_id' => 'fake', 'test' => true];
    }
}
