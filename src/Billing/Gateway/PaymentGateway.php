<?php

declare(strict_types=1);

namespace App\Billing\Gateway;

interface PaymentGateway
{
    public function isConfigured(): bool;

    /** @throws PaymentGatewayException */
    public function createPayment(PaymentRequest $request, string $idempotenceKey): GatewayPayment;

    /** @throws PaymentGatewayException */
    public function getPayment(string $id): GatewayPayment;

    /** @throws PaymentGatewayException */
    public function createRefund(string $paymentId, int $amount, string $description, string $idempotenceKey, ?string $receiptEmail = null, string $receiptItem = ''): GatewayRefund;

    /** @throws PaymentGatewayException */
    public function getRefund(string $id): GatewayRefund;

    /**
     * Проверка ключей: возвращает данные магазина.
     *
     * @return array<string,mixed>
     * @throws PaymentGatewayException
     */
    public function testConnection(): array;
}
