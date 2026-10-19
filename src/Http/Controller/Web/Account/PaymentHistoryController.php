<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Account;

use App\Billing\PaymentRepository;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Seo\SeoService;
use App\Settings\SettingsService;

/** История платежей и квитанции об оплате (печать / скачивание). */
final class PaymentHistoryController extends Controller
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly SeoService $seo,
    ) {
    }

    public function index(): Response
    {
        $user = $this->requireUser();
        $this->meta($this->seo->page('История платежей', '', '/account/payments', false));

        return $this->view('web/account/payments', ['section' => 'payments', 'payments' => $this->payments->forUser($user->id)]);
    }

    public function receipt(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $payment = $this->payments->find($id);
        if ($payment === null || $payment->userId !== $user->id || !$payment->isSucceeded()) {
            $this->notFound('Квитанция не найдена');
        }
        $html = $this->fragment('web/account/receipt', [
            'payment' => $payment,
            'payer' => $user,
            'seller' => [
                'name' => $this->settings->get('site.legal_name') ?: $this->settings->siteName(),
                'inn' => $this->settings->get('site.inn'),
                'site' => (string) parse_url($this->config->appUrl(), PHP_URL_HOST),
                'email' => $this->settings->get('site.contact_email'),
            ],
            'refunds' => $this->payments->refundsFor($payment->id),
        ]);
        if ($request->bool('download')) {
            return Response::download($html, 'receipt-' . $payment->id . '.html', 'text/html; charset=utf-8');
        }

        return Response::html($html)->withHeader('X-Robots-Tag', 'noindex');
    }
}
