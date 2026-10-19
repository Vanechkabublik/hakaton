<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controller\Admin\ArticleController;
use App\Http\Controller\Admin\CampaignController;
use App\Http\Controller\Admin\CommentModerationController;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\MediaController;
use App\Http\Controller\Admin\PageController;
use App\Http\Controller\Admin\PaymentController;
use App\Http\Controller\Admin\PlanController;
use App\Http\Controller\Admin\PromoCodeController;
use App\Http\Controller\Admin\SecurityController;
use App\Http\Controller\Admin\SettingsController;
use App\Http\Controller\Admin\SubscriptionController;
use App\Http\Controller\Admin\TaxonomyController;
use App\Http\Controller\Admin\UserController;

return static function (Router $r): void {
    $r->group(['prefix' => '/admin', 'middleware' => ['staff']], static function (Router $r): void {
        $r->get('', [DashboardController::class, 'index'])->name('admin');

        // ---- материалы (доступ проверяется внутри: автор видит только свои) ----
        $r->get('/articles', [ArticleController::class, 'index'])->name('admin.articles');
        $r->get('/articles/create', [ArticleController::class, 'create']);
        $r->post('/articles', [ArticleController::class, 'store']);
        $r->post('/articles/bulk', [ArticleController::class, 'bulk']);
        $r->get('/articles/{id:\d+}/edit', [ArticleController::class, 'edit']);
        $r->post('/articles/{id:\d+}', [ArticleController::class, 'update']);
        $r->post('/articles/{id:\d+}/delete', [ArticleController::class, 'delete']);
        $r->get('/articles/{id:\d+}/revisions', [ArticleController::class, 'revisions']);
        $r->get('/articles/{id:\d+}/revisions/{revision:\d+}', [ArticleController::class, 'revision']);
        $r->post('/articles/{id:\d+}/revisions/{revision:\d+}/restore', [ArticleController::class, 'restore']);
        $r->post('/articles/{id:\d+}/live', [ArticleController::class, 'addLive']);
        $r->post('/articles/{id:\d+}/live/{entry:\d+}', [ArticleController::class, 'updateLive']);
        $r->get('/api/articles/lookup', [ArticleController::class, 'lookup']);
        $r->get('/api/tags', [ArticleController::class, 'tagSuggest']);

        // ---- медиатека ----
        $r->group(['middleware' => ['can:media.upload']], static function (Router $r): void {
            $r->get('/media', [MediaController::class, 'index']);
            $r->post('/media', [MediaController::class, 'upload']);
            $r->get('/api/media', [MediaController::class, 'picker']);
            $r->post('/media/{id:\d+}', [MediaController::class, 'update']);
            $r->post('/media/{id:\d+}/delete', [MediaController::class, 'delete']);
        });

        // ---- редакция ----
        $r->group(['middleware' => ['can:taxonomy.manage']], static function (Router $r): void {
            $r->get('/categories', [TaxonomyController::class, 'categories']);
            $r->post('/categories', [TaxonomyController::class, 'saveCategory']);
            $r->post('/categories/{id:\d+}', [TaxonomyController::class, 'saveCategory']);
            $r->post('/categories/{id:\d+}/delete', [TaxonomyController::class, 'deleteCategory']);
            $r->get('/tags', [TaxonomyController::class, 'tags']);
            $r->post('/tags', [TaxonomyController::class, 'saveTag']);
            $r->post('/tags/{id:\d+}', [TaxonomyController::class, 'saveTag']);
            $r->post('/tags/{id:\d+}/delete', [TaxonomyController::class, 'deleteTag']);
            $r->post('/tags/{id:\d+}/merge', [TaxonomyController::class, 'mergeTag']);
        });
        $r->group(['middleware' => ['can:comments.moderate']], static function (Router $r): void {
            $r->get('/comments', [CommentModerationController::class, 'index']);
            $r->post('/comments/bulk', [CommentModerationController::class, 'bulk']);
            $r->post('/comments/{id:\d+}/moderate', [CommentModerationController::class, 'moderate']);
        });
        $r->group(['middleware' => ['can:pages.manage']], static function (Router $r): void {
            $r->get('/pages', [PageController::class, 'index']);
            $r->get('/pages/{id:\d+}/edit', [PageController::class, 'edit']);
            $r->post('/pages/{id:\d+}', [PageController::class, 'update']);
        });

        // ---- пользователи ----
        $r->group(['middleware' => ['can:users.manage']], static function (Router $r): void {
            $r->get('/users', [UserController::class, 'index']);
            $r->get('/users/export', [UserController::class, 'export']);
            $r->get('/users/create', [UserController::class, 'create']);
            $r->post('/users', [UserController::class, 'store']);
            $r->get('/users/{id:\d+}', [UserController::class, 'show']);
            $r->post('/users/{id:\d+}/role', [UserController::class, 'role']);
            $r->post('/users/{id:\d+}/block', [UserController::class, 'block']);
            $r->post('/users/{id:\d+}/unblock', [UserController::class, 'unblock']);
            $r->post('/users/{id:\d+}/grant', [UserController::class, 'grant']);
            $r->post('/users/{id:\d+}/revoke', [UserController::class, 'revoke']);
            $r->post('/users/{id:\d+}/reset-2fa', [UserController::class, 'resetTwoFactor']);
            $r->post('/users/{id:\d+}/send-reset', [UserController::class, 'sendReset']);
        });

        // ---- платежи ----
        $r->group(['middleware' => ['can:payments.manage']], static function (Router $r): void {
            $r->get('/payments', [PaymentController::class, 'index']);
            $r->get('/payments/export', [PaymentController::class, 'export']);
            $r->get('/payments/webhooks', [PaymentController::class, 'webhooks']);
            $r->post('/payments/reconcile', [PaymentController::class, 'reconcile']);
            $r->get('/payments/{id:\d+}', [PaymentController::class, 'show']);
            $r->post('/payments/{id:\d+}/refund', [PaymentController::class, 'refund']);
            $r->post('/payments/{id:\d+}/sync', [PaymentController::class, 'sync']);
        });

        // ---- подписки, тарифы, промокоды ----
        $r->group(['middleware' => ['can:subscriptions.manage']], static function (Router $r): void {
            $r->get('/subscriptions', [SubscriptionController::class, 'index']);
            $r->get('/plans', [PlanController::class, 'index']);
            $r->get('/plans/create', [PlanController::class, 'create']);
            $r->post('/plans', [PlanController::class, 'save']);
            $r->get('/plans/{id:\d+}/edit', [PlanController::class, 'edit']);
            $r->post('/plans/{id:\d+}', [PlanController::class, 'save']);
            $r->post('/plans/{id:\d+}/delete', [PlanController::class, 'delete']);
            $r->get('/promocodes', [PromoCodeController::class, 'index']);
            $r->get('/promocodes/create', [PromoCodeController::class, 'create']);
            $r->post('/promocodes', [PromoCodeController::class, 'save']);
            $r->get('/promocodes/{id:\d+}/edit', [PromoCodeController::class, 'edit']);
            $r->post('/promocodes/{id:\d+}', [PromoCodeController::class, 'save']);
            $r->post('/promocodes/{id:\d+}/delete', [PromoCodeController::class, 'delete']);
        });

        // ---- рассылки ----
        $r->group(['middleware' => ['can:newsletters.manage']], static function (Router $r): void {
            $r->get('/campaigns', [CampaignController::class, 'index']);
            $r->get('/campaigns/create', [CampaignController::class, 'create']);
            $r->post('/campaigns', [CampaignController::class, 'save']);
            $r->get('/campaigns/{id:\d+}/edit', [CampaignController::class, 'edit']);
            $r->get('/campaigns/{id:\d+}/preview', [CampaignController::class, 'preview']);
            $r->post('/campaigns/{id:\d+}', [CampaignController::class, 'save']);
            $r->post('/campaigns/{id:\d+}/schedule', [CampaignController::class, 'schedule']);
            $r->post('/campaigns/{id:\d+}/cancel', [CampaignController::class, 'cancel']);
            $r->post('/campaigns/{id:\d+}/test', [CampaignController::class, 'test']);
        });

        // ---- настройки ----
        $r->group(['middleware' => ['can:settings.manage']], static function (Router $r): void {
            $r->get('/settings', [SettingsController::class, 'show']);
            $r->post('/settings/test-mail', [SettingsController::class, 'testMail']);
            $r->post('/settings/test-yookassa', [SettingsController::class, 'testYooKassa']);
            $r->post('/settings/generate-vapid', [SettingsController::class, 'generateVapid']);
            $r->get('/settings/{group}', [SettingsController::class, 'show']);
            $r->post('/settings/{group}', [SettingsController::class, 'save']);
        });

        // ---- безопасность ----
        $r->group(['middleware' => ['can:security.manage']], static function (Router $r): void {
            $r->get('/security', [SecurityController::class, 'audit']);
            $r->get('/security/staff', [SecurityController::class, 'staff']);
            $r->post('/security/staff/{id:\d+}/reset-2fa', [SecurityController::class, 'resetTwoFactor']);
            $r->get('/security/database', [SecurityController::class, 'database']);
            $r->post('/security/database/export', [SecurityController::class, 'exportDatabase']);
            $r->post('/security/database/backup', [SecurityController::class, 'createBackup']);
            $r->get('/security/database/backups/{name}', [SecurityController::class, 'downloadBackup']);
            $r->get('/security/system', [SecurityController::class, 'system']);
            $r->post('/security/system/retry', [SecurityController::class, 'retryFailed']);
        });
    });
};
