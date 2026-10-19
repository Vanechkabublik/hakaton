<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Billing;

use App\Billing\CheckoutService;
use App\Billing\GiftService;
use App\Billing\PlanRepository;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Seo\SeoService;
use App\Settings\SettingsService;

/** Подарочные подписки: покупка и активация кода. */
final class GiftController extends Controller
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly CheckoutService $checkout,
        private readonly GiftService $gifts,
        private readonly SettingsService $settings,
        private readonly SeoService $seo,
    ) {
    }

    public function show(): Response
    {
        $this->meta($this->seo->page('Подарить подписку', 'Подарите близким доступ ко всем материалам', '/gift'));

        return $this->view('web/billing/gift', ['plans' => $this->plans->all(true), 'available' => $this->checkout->isAvailable()]);
    }

    public function purchase(Request $request): Response
    {
        $user = $this->requireUser();
        $data = $this->validate($request, [
            'plan_id' => 'required|int',
            'recipient_email' => 'required|email',
            'recipient_name' => 'nullable|string|max:120',
            'message' => 'nullable|string|max:1000',
        ], ['plan_id' => 'Тариф', 'recipient_email' => 'Email получателя', 'recipient_name' => 'Имя получателя', 'message' => 'Сообщение']);
        $plan = $this->plans->find((int) $data['plan_id']);
        if ($plan === null || !$plan->isActive) {
            throw new \DomainException('Выберите тариф');
        }
        $result = $this->checkout->startGift(
            $user,
            $plan,
            (string) $data['recipient_email'],
            (string) ($data['recipient_name'] ?? ''),
            (string) ($data['message'] ?? ''),
            $request->string('promo'),
            $request->string('mode', $this->settings->get('yookassa.confirmation')) === 'embedded' ? 'embedded' : 'redirect',
        );
        $payment = $result->payment ?? throw new \RuntimeException('Платёж не создан');
        if ($result->widgetToken !== null) {
            return $this->redirect('/payment/' . $payment->id . '/pay');
        }

        return $this->redirect($result->redirectUrl ?? '/payment/return/' . $payment->id);
    }

    public function redeemForm(Request $request): Response
    {
        $this->meta($this->seo->page('Активировать подарок', '', '/gift/redeem', false));

        return $this->view('web/billing/redeem', ['code' => $request->string('code')]);
    }

    public function redeem(Request $request): Response
    {
        $user = $this->requireUser();
        $subscription = $this->gifts->redeem($user, $request->string('code'));
        $this->flash('success', 'Подарок активирован! Подписка действует до ' . \App\Support\Format::date($subscription->currentPeriodEnd) . '.');

        return $this->redirect('/account/subscription');
    }
}
