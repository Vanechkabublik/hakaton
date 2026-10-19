<?php

declare(strict_types=1);

namespace App\Billing\Gateway;

use App\Billing\Money;
use App\Core\Http\HttpClient;
use App\Core\Logger;
use App\Settings\SettingsService;

/**
 * Клиент API ЮKassa v3 (https://yookassa.ru/developers/api).
 * Авторизация Basic (shopId:секретный ключ), идемпотентность через заголовок Idempotence-Key.
 */
final class YooKassaClient implements PaymentGateway
{
    private const BASE = 'https://api.yookassa.ru/v3';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('yookassa.shop_id') !== '' && $this->settings->get('yookassa.secret_key') !== '';
    }

    public function createPayment(PaymentRequest $request, string $idempotenceKey): GatewayPayment
    {
        $body = [
            'amount' => ['value' => Money::toDecimal($request->amount), 'currency' => 'RUB'],
            'capture' => true,
            'description' => mb_substr($request->description, 0, 128),
            'metadata' => (object) $request->metadata,
        ];
        if ($request->paymentMethodId !== null) {
            $body['payment_method_id'] = $request->paymentMethodId; // рекуррентный платёж без подтверждения
        } else {
            $body['confirmation'] = $request->confirmation === 'embedded'
                ? ['type' => 'embedded']
                : ['type' => 'redirect', 'return_url' => $request->returnUrl];
            if ($request->savePaymentMethod) {
                $body['save_payment_method'] = true;
            }
        }
        if ($this->settings->bool('yookassa.send_receipt') && $request->receiptEmail !== null) {
            $body['receipt'] = $this->receipt($request->receiptEmail, $request->receiptItem, $request->amount);
        }

        return GatewayPayment::fromArray($this->send('POST', '/payments', $body, $idempotenceKey));
    }

    public function getPayment(string $id): GatewayPayment
    {
        return GatewayPayment::fromArray($this->send('GET', '/payments/' . rawurlencode($id)));
    }

    public function createRefund(string $paymentId, int $amount, string $description, string $idempotenceKey, ?string $receiptEmail = null, string $receiptItem = ''): GatewayRefund
    {
        $body = [
            'payment_id' => $paymentId,
            'amount' => ['value' => Money::toDecimal($amount), 'currency' => 'RUB'],
            'description' => mb_substr($description, 0, 250),
        ];
        if ($this->settings->bool('yookassa.send_receipt') && $receiptEmail !== null) {
            $body['receipt'] = $this->receipt($receiptEmail, $receiptItem !== '' ? $receiptItem : 'Возврат за подписку', $amount);
        }

        return GatewayRefund::fromArray($this->send('POST', '/refunds', $body, $idempotenceKey));
    }

    public function getRefund(string $id): GatewayRefund
    {
        return GatewayRefund::fromArray($this->send('GET', '/refunds/' . rawurlencode($id)));
    }

    public function testConnection(): array
    {
        return $this->send('GET', '/me');
    }

    /** @return array<string,mixed> */
    private function receipt(string $email, string $item, int $amount): array
    {
        $receipt = [
            'customer' => ['email' => $email],
            'items' => [[
                'description' => mb_substr($item, 0, 128),
                'quantity' => '1.00',
                'amount' => ['value' => Money::toDecimal($amount), 'currency' => 'RUB'],
                'vat_code' => $this->settings->int('yookassa.vat_code', 1),
                'payment_mode' => 'full_payment',
                'payment_subject' => 'service',
            ]],
        ];
        $tax = $this->settings->get('yookassa.tax_system_code');
        if ($tax !== '') {
            $receipt['tax_system_code'] = (int) $tax;
        }

        return $receipt;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, ?array $body = null, ?string $idempotenceKey = null): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentGatewayException('ЮKassa не настроена: укажите shopId и секретный ключ в настройках.');
        }
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->settings->get('yookassa.shop_id') . ':' . $this->settings->get('yookassa.secret_key')),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($idempotenceKey !== null) {
            $headers['Idempotence-Key'] = $idempotenceKey;
        }
        $payload = $body !== null ? (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $attempts = 0;
        while (true) {
            ++$attempts;
            try {
                $response = $this->http->request($method, self::BASE . $path, $headers, $payload, 30);
            } catch (\RuntimeException $e) {
                if ($attempts < 2) {
                    continue; // повтор безопасен: тот же Idempotence-Key
                }
                throw new PaymentGatewayException('Платёжная система недоступна, попробуйте позже', null, null, $e);
            }
            // 202 — запрос принимается в обработку, нужно повторить с тем же ключом
            if ($response->status === 202 && $attempts < 4) {
                usleep(1_500_000);
                continue;
            }
            break;
        }

        $data = $response->json();
        if (!$response->ok()) {
            $this->logger->warning('Ошибка API ЮKassa', ['status' => $response->status, 'path' => $path, 'body' => $data]);
            $description = (string) ($data['description'] ?? 'Платёжная система отклонила запрос');
            throw new PaymentGatewayException($description, $response->status, isset($data['code']) ? (string) $data['code'] : null);
        }

        return $data;
    }
}
