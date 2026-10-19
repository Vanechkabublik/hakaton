<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Billing;

use App\Billing\CheckoutService;
use App\Billing\Gateway\PaymentGateway;
use App\Billing\Payment;
use App\Billing\PaymentProcessor;
use App\Billing\PaymentRepository;
use App\Core\Config;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Seo\SeoService;

/** Страница возврата после оплаты, виджет ЮKassa и опрос статуса платежа. */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentProcessor $processor,
        private readonly CheckoutService $checkout,
        private readonly PaymentGateway $gateway,
        private readonly Config $config,
        private readonly SeoService $seo,
    ) {
    }

    /** Пользователь вернулся с платёжной страницы: уточняем статус у ЮKassa, не дожидаясь вебхука. */
    public function back(int $id): Response
    {
        $payment = $this->own($id);
        if (!$payment->status->isFinal() && $payment->yookassaId !== null && $this->gateway->isConfigured()) {
            try {
                $payment = $this->processor->sync($payment);
            } catch (\Throwable) {
                // статус придёт вебхуком; страница ниже будет опрашивать его сама
            }
        }
        $this->meta($this->seo->page('Статус оплаты', '', '/payment/return/' . $payment->id, false));

        return $this->view('web/billing/return', ['payment' => $payment])->withHeader('Cache-Control', 'no-store');
    }

    /** Встроенный виджет ЮKassa (confirmation.type = embedded). */
    public function pay(int $id): Response
    {
        $payment = $this->own($id);
        $resume = $this->checkout->resume($payment);
        if ($resume === null || $resume->widgetToken === null) {
            return $resume?->redirectUrl !== null ? $this->redirect($resume->redirectUrl) : $this->redirect('/payment/return/' . $payment->id);
        }
        $this->meta($this->seo->page('Оплата подписки', '', '/payment/' . $payment->id . '/pay', false));

        return $this->view('web/billing/pay', [
            'payment' => $payment,
            'token' => $resume->widgetToken,
            'returnUrl' => $this->config->appUrl() . '/payment/return/' . $payment->id,
        ]);
    }

    /** AJAX: статус платежа для страницы ожидания. */
    public function status(int $id): Response
    {
        $payment = $this->own($id);
        if (!$payment->status->isFinal() && $payment->yookassaId !== null && $this->gateway->isConfigured()) {
            try {
                $payment = $this->processor->sync($payment);
            } catch (\Throwable) {
            }
        }

        return $this->json([
            'ok' => true,
            'status' => $payment->status->value,
            'final' => $payment->status->isFinal(),
            'label' => $payment->status->label(),
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function own(int $id): Payment
    {
        $user = $this->requireUser();
        $payment = $this->payments->find($id);
        if ($payment === null || $payment->userId !== $user->id) {
            $this->notFound('Платёж не найден');
        }

        return $payment;
    }
}
