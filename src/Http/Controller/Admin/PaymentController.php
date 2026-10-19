<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Billing\Gateway\PaymentGateway;
use App\Billing\Money;
use App\Billing\PaymentProcessor;
use App\Billing\PaymentRepository;
use App\Billing\RefundService;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\StreamedResponse;
use App\Http\AdminController;

/** Платежи: список, детали, возвраты (полные и частичные), сверка с ЮKassa, экспорт. */
final class PaymentController extends AdminController
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly RefundService $refunds,
        private readonly PaymentProcessor $processor,
        private readonly PaymentGateway $gateway,
        private readonly Database $db,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'kind', 'from', 'to']);
        [$page, $offset] = $this->paging($request);
        $result = $this->payments->adminSearch($filters, self::PER_PAGE, $offset);

        return $this->admin('payments/index', [
            'title' => 'Платежи',
            'section' => 'payments',
            'items' => $result['items'],
            'total' => $result['total'],
            'sum' => $result['sum'],
            'filters' => $filters,
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
            'gatewayReady' => $this->gateway->isConfigured(),
        ]);
    }

    public function show(int $id): Response
    {
        $payment = $this->payments->find($id) ?? $this->notFound();
        $events = $payment->yookassaId !== null
            ? $this->db->fetchAll('SELECT id, event, status, error, received_at FROM webhook_events WHERE object_id = :o ORDER BY id DESC LIMIT 20', ['o' => $payment->yookassaId])
            : [];

        return $this->admin('payments/show', [
            'title' => 'Платёж #' . $payment->id,
            'section' => 'payments',
            'payment' => $payment,
            'refunds' => $this->payments->refundsFor($payment->id),
            'events' => $events,
            'gatewayReady' => $this->gateway->isConfigured(),
        ]);
    }

    public function refund(Request $request, int $id): Response
    {
        $payment = $this->payments->find($id) ?? $this->notFound();
        $amountInput = $request->string('amount');
        $amount = $amountInput === '' ? null : Money::fromInput($amountInput);
        $this->refunds->refund($payment, $amount, $request->string('reason'), $this->requireUser());

        return $this->done($request, 'Возврат оформлен', '/admin/payments/' . $id);
    }

    public function sync(Request $request, int $id): Response
    {
        $payment = $this->payments->find($id) ?? $this->notFound();
        $before = $payment->status->label();
        $after = $this->processor->sync($payment);
        $this->audit->record('payment.synced', $this->requireUser()->id, 'payment', $id);

        return $this->done($request, "Статус обновлён из ЮKassa: {$before} → {$after->status->label()}", '/admin/payments/' . $id);
    }

    /** Сверка недавних платежей с ЮKassa. */
    public function reconcile(Request $request): Response
    {
        $result = $this->processor->reconcile($this->payments->recentWithYookassaId(14, 300));
        $this->audit->record('payments.reconciled', $this->requireUser()->id, null, null, $result);

        return $this->done($request, sprintf('Сверка завершена: проверено %d, исправлено %d, ошибок %d', $result['checked'], $result['fixed'], $result['errors']), '/admin/payments');
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'kind', 'from', 'to']);
        $this->audit->record('payments.export', $this->requireUser()->id, null, null, $filters);

        return StreamedResponse::csv('payments-' . date('Ymd') . '.csv', function (callable $write) use ($filters): void {
            $write(['ID', 'Дата создания', 'Дата оплаты', 'Пользователь', 'Email', 'Описание', 'Тип', 'Статус', 'Сумма, ₽', 'Скидка, ₽', 'Возврат, ₽', 'ID в ЮKassa']);
            foreach ($this->payments->exportRows($filters) as $row) {
                $write([
                    $row['id'], $row['created_at'], $row['paid_at'], $row['user_name'], $row['user_email'] ?? $row['customer_email'], $row['description'],
                    $row['kind'], $row['status'], Money::toDecimal((int) $row['amount']), Money::toDecimal((int) $row['discount_amount']),
                    Money::toDecimal((int) $row['refunded_amount']), $row['yookassa_id'],
                ]);
            }
        });
    }

    /** Журнал вебхуков ЮKassa. */
    public function webhooks(): Response
    {
        return $this->admin('payments/webhooks', [
            'title' => 'Вебхуки ЮKassa',
            'section' => 'payments',
            'events' => $this->db->fetchAll('SELECT id, event, object_id, status, error, ip, received_at, processed_at FROM webhook_events ORDER BY id DESC LIMIT 150'),
        ]);
    }
}
