<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controller\Web\Billing\GiftController;
use App\Http\Controller\Web\Billing\PaymentController;
use App\Http\Controller\Web\Billing\SubscribeController;
use App\Http\Controller\Web\Billing\WebhookController;

return static function (Router $r): void {
    $r->get('/subscribe', [SubscribeController::class, 'plans'])->name('subscribe');

    $r->group(['middleware' => ['auth']], static function (Router $r): void {
        $r->get('/subscribe/checkout/{id:\d+}', [SubscribeController::class, 'checkout'])->name('checkout');
        $r->post('/subscribe/checkout/{id:\d+}', [SubscribeController::class, 'start'])->middleware('rate:payments,10,600')->name('checkout.start');
        $r->post('/subscribe/checkout/{id:\d+}/quote', [SubscribeController::class, 'quote'])->middleware('rate:promo,30,300');
        $r->post('/subscribe/trial/{id:\d+}', [SubscribeController::class, 'trial'])->middleware('rate:trial,5,3600');

        $r->get('/payment/{id:\d+}/pay', [PaymentController::class, 'pay'])->name('payment.pay');
        $r->get('/payment/return/{id:\d+}', [PaymentController::class, 'back'])->name('payment.return');
        $r->get('/api/payments/{id:\d+}/status', [PaymentController::class, 'status'])->middleware('rate:payment-status,120,300');

        $r->get('/gift/redeem', [GiftController::class, 'redeemForm'])->name('gift.redeem');
        $r->post('/gift/redeem', [GiftController::class, 'redeem'])->middleware('rate:gift-redeem,10,600');
        $r->post('/gift', [GiftController::class, 'purchase'])->middleware('rate:payments,10,600');
    });
    $r->get('/gift', [GiftController::class, 'show'])->name('gift');

    // уведомления ЮKassa (без CSRF и авторизации)
    $r->post('/webhooks/yookassa', [WebhookController::class, 'yookassa']);
};
