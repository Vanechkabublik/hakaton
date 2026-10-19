<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controller\Web\Account\AccountController;
use App\Http\Controller\Web\Account\LibraryController;
use App\Http\Controller\Web\Account\NotificationController;
use App\Http\Controller\Web\Account\PaymentHistoryController;
use App\Http\Controller\Web\Account\SecurityController;
use App\Http\Controller\Web\Account\SubscriptionController;

return static function (Router $r): void {
    $r->group(['prefix' => '/account', 'middleware' => ['auth']], static function (Router $r): void {
        $r->get('', [AccountController::class, 'overview'])->name('account');
        $r->get('/profile', [AccountController::class, 'profile'])->name('account.profile');
        $r->post('/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
        $r->post('/avatar', [AccountController::class, 'updateAvatar'])->middleware('rate:avatar,10,3600');
        $r->post('/password', [AccountController::class, 'updatePassword'])->middleware('rate:password,10,3600');
        $r->post('/email', [AccountController::class, 'updateEmail'])->middleware('rate:email,5,3600');
        $r->post('/yandex/link', [AccountController::class, 'linkYandex']);
        $r->post('/yandex/unlink', [AccountController::class, 'unlinkYandex']);
        $r->post('/delete', [AccountController::class, 'delete'])->middleware('rate:delete,5,3600');

        $r->get('/subscription', [SubscriptionController::class, 'show'])->name('account.subscription');
        $r->post('/subscription/auto-renew', [SubscriptionController::class, 'autoRenew']);
        $r->post('/subscription/cancel', [SubscriptionController::class, 'cancel']);
        $r->post('/subscription/method/remove', [SubscriptionController::class, 'removeMethod']);
        $r->post('/subscription/pending-change/cancel', [SubscriptionController::class, 'clearPendingChange']);

        $r->get('/payments', [PaymentHistoryController::class, 'index'])->name('account.payments');
        $r->get('/payments/{id:\d+}/receipt', [PaymentHistoryController::class, 'receipt'])->name('account.receipt');

        $r->get('/bookmarks', [LibraryController::class, 'bookmarks'])->name('account.bookmarks');
        $r->get('/history', [LibraryController::class, 'history'])->name('account.history');
        $r->post('/history/clear', [LibraryController::class, 'clearHistory']);
        $r->get('/comments', [LibraryController::class, 'comments'])->name('account.comments');

        $r->get('/notifications', [NotificationController::class, 'show'])->name('account.notifications');
        $r->post('/notifications', [NotificationController::class, 'update']);
        $r->post('/push/subscribe', [NotificationController::class, 'pushSubscribe'])->middleware('rate:push,20,3600');
        $r->post('/push/unsubscribe', [NotificationController::class, 'pushUnsubscribe']);

        $r->get('/two-factor', [SecurityController::class, 'show'])->name('account.security');
        $r->post('/two-factor/enable', [SecurityController::class, 'enable'])->middleware('rate:2fa-setup,20,900');
        $r->post('/two-factor/disable', [SecurityController::class, 'disable'])->middleware('rate:2fa-setup,20,900');
        $r->post('/two-factor/recovery', [SecurityController::class, 'regenerate'])->middleware('rate:2fa-setup,20,900');
        $r->post('/sessions/logout-others', [SecurityController::class, 'logoutOthers']);
    });
};
