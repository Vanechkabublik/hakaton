<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Billing;

use App\Article\ArticleRepository;
use App\Billing\CheckoutService;
use App\Billing\Plan;
use App\Billing\PlanRepository;
use App\Billing\PlanTier;
use App\Billing\SubscriptionService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Session;
use App\Http\Controller;
use App\Paywall\PaywallService;
use App\Paywall\VisitorResolver;
use App\Seo\SeoService;
use App\Settings\SettingsService;

/** Витрина тарифов, оформление подписки и пробный период. */
final class SubscribeController extends Controller
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly ArticleRepository $articles,
        private readonly CheckoutService $checkout,
        private readonly SubscriptionService $subscriptions,
        private readonly PaywallService $paywall,
        private readonly VisitorResolver $visitors,
        private readonly Session $session,
        private readonly SettingsService $settings,
        private readonly SeoService $seo,
    ) {
    }

    public function plans(Request $request): Response
    {
        $from = $request->int('from');
        $visitor = $this->visitors->resolve($request);
        if ($from > 0) {
            $this->paywall->recordCtaClick($from, $visitor);
            $this->session->set('paywall_article', $from);
        }
        $this->meta($this->seo->page('Подписка', 'Тарифы: полный доступ ко всем материалам', '/subscribe'));

        $user = $this->user();
        $grouped = [];
        foreach ($this->plans->all(true) as $plan) {
            $grouped[$plan->tier->value][$plan->period->value] = $plan;
        }
        $current = $user !== null ? $this->subscriptions->liveFor($user) : null;
        $trialPlan = null;
        foreach ($this->plans->all(true) as $plan) {
            if ($plan->trialDays > 0 && $user !== null && $this->subscriptions->canStartTrial($user, $plan)) {
                $trialPlan = $plan;
                break;
            }
        }
        $response = $this->view('web/billing/plans', [
            'grouped' => $grouped,
            'tiers' => PlanTier::cases(),
            'current' => $current,
            'trialPlan' => $trialPlan,
            'freeLimit' => $this->paywall->freeLimit(),
            'available' => $this->checkout->isAvailable(),
        ]);
        $this->visitors->attach($response, $visitor, $request);

        return $response;
    }

    public function checkout(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $plan = $this->plan($id);
        $quote = $this->checkout->quote($user, $plan, $request->string('promo'));
        $this->meta($this->seo->page('Оформление подписки', '', '/subscribe/checkout/' . $plan->id, false));

        return $this->view('web/billing/checkout', [
            'plan' => $plan,
            'quote' => $quote,
            'promoCode' => $request->string('promo'),
            'available' => $this->checkout->isAvailable(),
            'recurring' => $this->checkout->recurringEnabled(),
            'defaultMode' => $this->settings->get('yookassa.confirmation'),
            'canTrial' => $quote->current === null && $this->subscriptions->canStartTrial($user, $plan),
        ]);
    }

    /** AJAX: пересчёт стоимости с промокодом. */
    public function quote(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $plan = $this->plan($id);
        $quote = $this->checkout->quote($user, $plan, $request->string('promo'));

        return $this->json([
            'ok' => true,
            'promo_error' => $quote->promoError,
            'promo_label' => $quote->promo?->label(),
            'price' => $quote->price,
            'credit' => $quote->credit,
            'discount' => $quote->discount,
            'total' => $quote->total,
            'price_text' => \App\Support\Format::money($quote->price),
            'credit_text' => \App\Support\Format::money($quote->credit),
            'discount_text' => \App\Support\Format::money($quote->discount),
            'total_text' => \App\Support\Format::money($quote->total, true),
        ]);
    }

    public function start(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $plan = $this->plan($id);
        $mode = $request->string('mode', $this->settings->get('yookassa.confirmation'));
        $paywallArticle = $this->session->get('paywall_article');
        $result = $this->checkout->start(
            $user,
            $plan,
            $request->string('promo'),
            $mode === 'embedded' ? 'embedded' : 'redirect',
            $request->bool('auto_renew'),
            is_int($paywallArticle) ? $paywallArticle : null,
        );
        if ($result->scheduledChange) {
            $this->flash('success', 'Готово: тариф «' . $plan->title() . '» вступит в силу со следующего периода.');

            return $this->redirect('/account/subscription');
        }
        $payment = $result->payment ?? throw new \RuntimeException('Платёж не создан');
        if ($result->widgetToken !== null) {
            return $this->redirect('/payment/' . $payment->id . '/pay');
        }

        return $this->redirect($result->redirectUrl ?? '/payment/return/' . $payment->id);
    }

    public function trial(int $id): Response
    {
        $user = $this->requireUser();
        $plan = $this->plan($id);
        $sub = $this->subscriptions->startTrial($user, $plan);
        $this->flash('success', 'Пробный период активирован до ' . \App\Support\Format::date($sub->currentPeriodEnd) . '. Приятного чтения!');
        // Вернуть читателя к статье, на которой он упёрся в пейволл
        $articleId = $this->session->pull('paywall_article');
        $article = is_int($articleId) ? $this->articles->find($articleId) : null;

        return $this->redirect($article !== null && $article->isPublished() ? $article->url() : '/account/subscription');
    }

    private function plan(int $id): Plan
    {
        $plan = $this->plans->find($id);
        if ($plan === null || !$plan->isActive) {
            $this->notFound('Тариф не найден');
        }

        return $plan;
    }
}
