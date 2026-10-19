<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Account;

use App\Article\ArticleRepository;
use App\Audit\AuditLog;
use App\Auth\Auth;
use App\Auth\AuthService;
use App\Auth\YandexAuthService;
use App\Billing\SubscriptionService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Engagement\BookmarkRepository;
use App\Engagement\ReadingHistoryRepository;
use App\Http\Controller;
use App\Media\ImageException;
use App\Seo\SeoService;
use App\User\AccountService;
use App\User\UserRepository;

/** Личный кабинет: обзор, профиль, аватар, пароль, email, удаление аккаунта. */
final class AccountController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Auth $auth,
        private readonly AuthService $authService,
        private readonly AccountService $account,
        private readonly YandexAuthService $yandex,
        private readonly SubscriptionService $subscriptions,
        private readonly BookmarkRepository $bookmarks,
        private readonly ReadingHistoryRepository $history,
        private readonly ArticleRepository $articles,
        private readonly AuditLog $audit,
        private readonly SeoService $seo,
    ) {
    }

    public function overview(): Response
    {
        $user = $this->requireUser();
        $this->meta($this->seo->page('Личный кабинет', '', '/account', false));
        $saved = $this->bookmarks->page($user->id, 3, 0);
        $recent = $this->history->page($user->id, 3, 0);

        return $this->view('web/account/overview', [
            'section' => 'overview',
            'subscription' => $this->subscriptions->liveFor($user),
            'bookmarks' => $this->articles->cardsByIds($saved['ids']),
            'bookmarksTotal' => $saved['total'],
            'recent' => $this->articles->cardsByIds(array_column($recent['items'], 'article_id')),
        ]);
    }

    public function profile(): Response
    {
        $user = $this->requireUser();
        $this->meta($this->seo->page('Профиль', '', '/account/profile', false));

        return $this->view('web/account/profile', [
            'section' => 'profile',
            'yandexEnabled' => $this->yandex->isConfigured(),
            'yandexIdentity' => $this->yandex->identityFor($user),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->requireUser();
        $data = $this->validate($request, ['name' => 'required|string|min:2|max:80', 'bio' => 'nullable|string|max:500'], ['name' => 'Имя', 'bio' => 'О себе']);
        $changes = ['name' => (string) $data['name']];
        if ($user->isStaff()) {
            $changes['bio'] = (string) ($data['bio'] ?? '');
        }
        $this->users->update($user->id, $changes);
        $this->flash('success', 'Профиль обновлён');

        return $this->redirect('/account/profile');
    }

    public function updateAvatar(Request $request): Response
    {
        $user = $this->requireUser();
        if ($request->bool('remove')) {
            $this->account->removeAvatar($user);
            $this->flash('success', 'Аватар удалён');

            return $this->redirect('/account/profile');
        }
        $file = $request->file('avatar');
        if ($file === null) {
            throw new ValidationException(['avatar' => 'Выберите файл изображения']);
        }
        try {
            $this->account->changeAvatar($user, $file);
        } catch (ImageException $e) {
            throw new ValidationException(['avatar' => $e->getMessage()]);
        }
        $this->flash('success', 'Аватар обновлён');

        return $this->redirect('/account/profile');
    }

    public function updatePassword(Request $request): Response
    {
        $user = $this->requireUser();
        $rules = ['password' => 'required|string|min:8|max:128', 'password_confirmation' => 'required|same:password'];
        if ($user->hasPassword()) {
            $rules['current_password'] = 'required|string';
        }
        $data = $this->validate($request, $rules, ['password' => 'Новый пароль', 'password_confirmation' => 'Подтверждение', 'current_password' => 'Текущий пароль']);
        $this->authService->changePassword($user, $data['current_password'] ?? null, (string) $data['password']);
        $this->flash('success', 'Пароль изменён. Другие устройства разлогинены.');

        return $this->redirect('/account/profile');
    }

    public function updateEmail(Request $request): Response
    {
        $user = $this->requireUser();
        $data = $this->validate($request, ['email' => 'required|email'], ['email' => 'Новый email']);
        if (mb_strtolower((string) $data['email']) === mb_strtolower($user->email)) {
            throw new ValidationException(['email' => 'Это ваш текущий адрес']);
        }
        $this->authService->requestEmailChange($user, (string) $data['email']);
        $this->flash('success', 'Мы отправили письмо на новый адрес. Перейдите по ссылке из него, чтобы подтвердить смену.');

        return $this->redirect('/account/profile');
    }

    public function linkYandex(): Response
    {
        $user = $this->requireUser();
        if (!$this->yandex->isConfigured()) {
            throw new \DomainException('Вход через Яндекс пока не настроен');
        }

        return $this->redirect($this->yandex->begin('link', null, $user->id));
    }

    public function unlinkYandex(): Response
    {
        $this->yandex->unlink($this->requireUser());
        $this->flash('success', 'Яндекс ID отвязан');

        return $this->redirect('/account/profile');
    }

    public function delete(Request $request): Response
    {
        $user = $this->requireUser();
        if (mb_strtolower($request->string('confirm')) !== 'удалить') {
            throw new ValidationException(['confirm' => 'Для подтверждения введите слово «удалить»']);
        }
        $this->account->delete($user, $request->string('password'), $request->bool('erase_comments'));
        // сессия и remember-токены удалены каскадом; сбрасываем состояние текущего запроса и cookie
        $this->auth->logout($request);
        $this->flash('success', 'Аккаунт удалён. Нам жаль, что вы уходите.');

        return $this->redirect('/');
    }
}
