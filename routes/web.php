<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controller\Web\ArticleController;
use App\Http\Controller\Web\AuthController;
use App\Http\Controller\Web\AuthorController;
use App\Http\Controller\Web\CommentController;
use App\Http\Controller\Web\EngagementController;
use App\Http\Controller\Web\FeedController;
use App\Http\Controller\Web\HomeController;
use App\Http\Controller\Web\OAuthController;
use App\Http\Controller\Web\PageController;
use App\Http\Controller\Web\SearchController;
use App\Http\Controller\Web\SeoController;
use App\Http\Controller\Web\TrackController;
use App\Http\Controller\Web\UnsubscribeController;

return static function (Router $r): void {
    // ---- публичные страницы --------------------------------------------------------------
    $r->get('/', [HomeController::class, 'index'])->name('home');
    $r->get('/category/{slug}', [HomeController::class, 'category'])->name('category');
    $r->get('/tag/{slug}', [HomeController::class, 'tag'])->name('tag');
    $r->get('/type/{type}', [HomeController::class, 'type'])->name('type');
    $r->get('/authors', [AuthorController::class, 'index'])->name('authors');
    $r->get('/author/{slug}', [AuthorController::class, 'show'])->name('author');
    $r->get('/news/{slug}', [ArticleController::class, 'show'])->name('article');
    $r->get('/preview/{id:\d+}', [ArticleController::class, 'preview'])->name('article.preview');
    $r->get('/search', [SearchController::class, 'index'])->name('search');

    // статические страницы (хранятся в БД, редактируются в админке)
    $r->get('/{slug:about|contacts|advertising|comment-rules|terms|privacy}', [PageController::class, 'show'])->name('page');

    // ---- SEO и ленты ---------------------------------------------------------------------
    $r->get('/rss.xml', [SeoController::class, 'rss'])->name('rss');
    $r->get('/rss/{slug}.xml', [SeoController::class, 'rssCategory'])->name('rss.category');
    $r->get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
    $r->get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
    $r->get('/manifest.webmanifest', [SeoController::class, 'manifest'])->name('manifest');

    // ---- вход, регистрация ---------------------------------------------------------------
    $r->group(['middleware' => ['guest']], static function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'])->name('login');
        $r->post('/login', [AuthController::class, 'login'])->middleware('rate:login,30,900')->name('login.submit');
        $r->get('/register', [AuthController::class, 'showRegister'])->name('register');
        $r->post('/register', [AuthController::class, 'register'])->middleware('rate:register,10,3600')->name('register.submit');
        $r->get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.forgot');
        $r->post('/forgot-password', [AuthController::class, 'forgot'])->middleware('rate:forgot,10,3600')->name('password.forgot.submit');
    });
    $r->get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    $r->post('/reset-password/{token}', [AuthController::class, 'reset'])->middleware('rate:reset,10,3600')->name('password.reset.submit');
    $r->get('/verify-email/pending', [AuthController::class, 'verifyNotice'])->name('verify.notice');
    $r->get('/verify-email/{token}', [AuthController::class, 'verify'])->name('verify');
    $r->post('/verify-email/resend', [AuthController::class, 'resendVerification'])->middleware('rate:resend,5,3600')->name('verify.resend');
    $r->get('/confirm-email-change/{token}', [AuthController::class, 'confirmEmailChange'])->name('email.confirm');
    $r->post('/logout', [AuthController::class, 'logout'])->name('logout');

    // второй фактор (для входа и для повышения сессии в админке)
    $r->get('/login/two-factor', [AuthController::class, 'showTwoFactor'])->name('login.two-factor');
    $r->post('/login/two-factor', [AuthController::class, 'twoFactor'])->middleware('rate:2fa,20,900')->name('login.two-factor.submit');

    // Яндекс ID
    $r->get('/auth/yandex', [OAuthController::class, 'redirect'])->name('oauth.yandex');
    $r->get('/auth/yandex/callback', [OAuthController::class, 'callback'])->name('oauth.yandex.callback');

    // ---- отписка по ссылке из письма --------------------------------------------------------
    $r->get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe');
    $r->post('/unsubscribe/{token}', [UnsubscribeController::class, 'update'])->name('unsubscribe.update');

    // ---- AJAX API ----------------------------------------------------------------------
    $r->get('/api/feed', [FeedController::class, 'feed'])->middleware('rate:feed,120,60')->name('api.feed');
    $r->get('/api/search/suggest', [SearchController::class, 'suggest'])->middleware('rate:suggest,90,60')->name('api.suggest');
    $r->get('/api/articles/{id:\d+}/live', [ArticleController::class, 'live'])->middleware('rate:live,120,60')->name('api.live');
    $r->post('/api/track/progress', [TrackController::class, 'progress'])->middleware('rate:track,60,60')->name('api.track.progress');

    $r->group(['middleware' => ['auth']], static function (Router $r): void {
        $r->post('/api/articles/{id:\d+}/react', [EngagementController::class, 'react'])->middleware('rate:react,60,60')->name('api.react');
        $r->post('/api/articles/{id:\d+}/bookmark', [EngagementController::class, 'bookmark'])->middleware('rate:bookmark,60,60')->name('api.bookmark');
        $r->post('/api/authors/{id:\d+}/follow', [AuthorController::class, 'follow'])->middleware('rate:follow,30,60')->name('api.follow');

        $r->post('/api/articles/{id:\d+}/comments', [CommentController::class, 'store'])->middleware('rate:comments,6,60')->name('api.comments.store');
        $r->post('/api/comments/{id:\d+}', [CommentController::class, 'update'])->middleware('rate:comments-edit,20,60')->name('api.comments.update');
        $r->post('/api/comments/{id:\d+}/like', [CommentController::class, 'like'])->middleware('rate:comments-like,60,60')->name('api.comments.like');
        $r->post('/api/comments/{id:\d+}/report', [CommentController::class, 'report'])->middleware('rate:comments-report,10,3600')->name('api.comments.report');
    });
};
