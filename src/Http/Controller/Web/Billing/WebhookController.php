<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Billing;

use App\Billing\PaymentProcessor;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logger;
use App\Http\Controller;

/**
 * Приёмник HTTP-уведомлений ЮKassa (https://yookassa.ru/developers/using-api/webhooks).
 * Тело запроса не считается доверенным: PaymentProcessor перечитывает объект через API.
 * Ответ 200 означает «принято», любой другой код заставит ЮKassa повторить доставку.
 */
final class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentProcessor $processor,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    public function yookassa(Request $request): Response
    {
        $payload = $request->json();
        $event = (string) ($payload['event'] ?? '');
        $objectId = (string) ($payload['object']['id'] ?? '');
        if (($payload['type'] ?? '') !== 'notification' || $event === '' || $objectId === '') {
            return Response::text('Bad Request', 400);
        }

        $logId = (int) $this->db->insert('webhook_events', [
            'event' => $event,
            'object_id' => $objectId,
            'payload' => $payload,
            'ip' => $request->ip(),
        ]);
        try {
            $result = $this->processor->handleWebhook($payload);
            $this->db->update('webhook_events', ['status' => $result === 'processed' ? 'processed' : 'ignored', 'processed_at' => new \DateTimeImmutable()], ['id' => $logId]);
        } catch (\Throwable $e) {
            $this->logger->error('Ошибка обработки вебхука ЮKassa', ['event' => $event, 'object' => $objectId, 'exception' => $e]);
            $this->db->update('webhook_events', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)], ['id' => $logId]);

            return Response::text('Error', 500);
        }

        return Response::text('OK');
    }
}
