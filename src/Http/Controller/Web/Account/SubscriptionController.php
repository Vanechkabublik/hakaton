<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Account;

use App\Billing\GiftRepository;
use App\Billing\PlanRepository;
use App\Billing\SubscriptionRepository;
use App\Billing\SubscriptionService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Seo\SeoService;

/** Моя подписка: тариф, статус, автопродление, отмена, смена тарифа. */
final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionRepository $subs,
        private readonly PlanRepository $plans,
        private readonly GiftRepository $gifts,
        private readonly SeoService $seo,
    ) {
    }

    public function show(): Response
    {
        $user = $this->requireUser();
        $this->meta($this->seo->page('Моя подписка', '', '/account/subscription', false));
        $live = $this->subscriptions->liveFor($user);

        return $this->view('web/account/subscription', [
            'section' => 'subscription',
            'subscription' => $live,
            'currentPlan' => $live !== null ? $this->plans->find($live->planId) : null,
            'pendingPlan' => $live?->pendingPlanId !== null ? $this->plans->find($live->pendingPlanId) : null,
            'plans' => $this->plans->all(true),
            'history' => $this->subs->historyForUser($user->id),
            'gifts' => $this->gifts->forBuyer($user->id),
            'canTrial' => $live === null && ($trialPlan = $this->trialPlan()) !== null && $this->subscriptions->canStartTrial($user, $trialPlan),
            'trialPlan' => $this->trialPlan(),
        ]);
    }

    public function autoRenew(Request $request): Response
    {
        $user = $this->requireUser();
        $sub = $this->subscriptions->liveFor($user) ?? throw new \DomainException('Активной подписки нет');
        $enable = $request->bool('enabled');
        $this->subscriptions->setAutoRenew($sub, $enable);
        $this->flash('success', $enable ? 'Автопродление включено' : 'Автопродление выключено. Доступ сохранится до конца оплаченного периода.');

        return $this->redirect('/account/subscription');
    }

    public function cancel(Request $request): Response
    {
        $user = $this->requireUser();
        $sub = $this->subscriptions->liveFor($user) ?? throw new \DomainException('Активной подписки нет');
        $this->subscriptions->cancel($sub, $request->string('reason') ?: null, $user);
        $this->flash('success', 'Подписка отменена: автопродление выключено, доступ сохранится до ' . $this->dateOf($sub->currentPeriodEnd) . '.');

        return $this->redirect('/account/subscription');
    }

    public function removeMethod(): Response
    {
        $user = $this->requireUser();
        $sub = $this->subscriptions->liveFor($user) ?? throw new \DomainException('Активной подписки нет');
        $this->subscriptions->forgetPaymentMethod($sub);
        $this->flash('success', 'Сохранённый способ оплаты удалён, автопродление выключено');

        return $this->redirect('/account/subscription');
    }

    public function clearPendingChange(): Response
    {
        $user = $this->requireUser();
        $sub = $this->subscriptions->liveFor($user) ?? throw new \DomainException('Активной подписки нет');
        $this->subscriptions->clearScheduledChange($sub);
        $this->flash('success', 'Запланированная смена тарифа отменена');

        return $this->redirect('/account/subscription');
    }

    private function trialPlan(): ?\App\Billing\Plan
    {
        foreach ($this->plans->all(true) as $plan) {
            if ($plan->trialDays > 0) {
                return $plan;
            }
        }

        return null;
    }

    private function dateOf(\DateTimeImmutable $date): string
    {
        return \App\Support\Format::date($date);
    }
}
