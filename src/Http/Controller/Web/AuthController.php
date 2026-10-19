<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Auth\Auth;
use App\Auth\AuthService;
use App\Auth\LoginResult;
use App\Auth\TwoFactorService;
use App\Auth\YandexAuthService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Core\Security\Session;
use App\Http\Controller;
use App\Notification\OnboardingService;
use App\Seo\SeoService;
use App\User\UserRepository;

/** Регистрация, вход, подтверждение email, восстановление пароля, второй фактор. */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly TwoFactorService $twoFactor,
        private readonly UserRepository $users,
        private readonly OnboardingService $onboarding,
        private readonly YandexAuthService $yandex,
        private readonly SeoService $seo,
    ) {
    }

    // ---- вход --------------------------------------------------------------------------

    public function showLogin(): Response
    {
        $this->meta($this->seo->page('Вход', 'Вход в аккаунт', '/login', false));

        return $this->view('web/auth/login', ['yandexEnabled' => $this->yandex->isConfigured()]);
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email', 'password' => 'required|string|max:200'], ['email' => 'Email', 'password' => 'Пароль']);
        $intended = $this->session->get('intended');
        $result = $this->authService->attempt(
            (string) $data['email'],
            (string) $data['password'],
            $request->bool('remember'),
            $request,
            is_string($intended) ? $intended : null,
        );

        switch ($result->status) {
            case LoginResult::OK:
                $this->session->forget('intended');

                return $this->redirect($this->safeLocal(is_string($intended) ? $intended : null, '/account'));
            case LoginResult::TWO_FACTOR:
                return $this->redirect('/login/two-factor');
            case LoginResult::LOCKED:
                throw new ValidationException(['email' => 'Слишком много попыток входа. Повторите через ' . max(1, (int) ceil($result->retryAfter / 60)) . ' мин.']);
            case LoginResult::UNVERIFIED:
                $this->session->set('verify_email', (string) $data['email']);
                $this->flash('warning', 'Подтвердите email: мы отправили письмо со ссылкой при регистрации.');

                return $this->redirect('/verify-email/pending');
            case LoginResult::BLOCKED:
                throw new ValidationException(['email' => 'Аккаунт заблокирован. Напишите в поддержку, если это ошибка.']);
            default:
                throw new ValidationException(['email' => 'Неверный email или пароль']);
        }
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);
        $this->session->flash('success', 'Вы вышли из аккаунта');

        return $this->redirect('/');
    }

    // ---- регистрация и подтверждение email -----------------------------------------------

    public function showRegister(): Response
    {
        $this->meta($this->seo->page('Регистрация', 'Создайте аккаунт, чтобы комментировать и сохранять статьи', '/register', false));

        return $this->view('web/auth/register', ['yandexEnabled' => $this->yandex->isConfigured()]);
    }

    public function register(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|string|min:2|max:80',
            'email' => 'required|email',
            'password' => 'required|string|min:8|max:128',
            'password_confirmation' => 'required|same:password',
            'terms' => 'required',
        ], [
            'name' => 'Имя', 'email' => 'Email', 'password' => 'Пароль',
            'password_confirmation' => 'Подтверждение пароля', 'terms' => 'Согласие с условиями',
        ]);
        $this->authService->register([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'password' => (string) $data['password'],
            'digest' => $request->bool('digest'),
            'marketing' => $request->bool('marketing'),
        ]);
        $this->session->set('verify_email', (string) $data['email']);

        return $this->redirect('/verify-email/pending');
    }

    public function verifyNotice(): Response
    {
        $this->meta($this->seo->page('Подтвердите email', '', '/verify-email/pending', false));

        return $this->view('web/auth/verify-notice', ['email' => (string) $this->session->get('verify_email', '')]);
    }

    public function verify(Request $request, string $token): Response
    {
        $user = $this->authService->verifyEmail($token);
        if ($user === null) {
            $this->flash('error', 'Ссылка недействительна или устарела. Запросите новое письмо.');

            return $this->redirect('/verify-email/pending');
        }
        $this->onboarding->welcome($user);
        $this->flash('success', 'Email подтверждён. Теперь вы можете войти.');

        return $this->redirect($this->auth->check() ? '/account' : '/login');
    }

    public function resendVerification(Request $request): Response
    {
        $email = $request->string('email', (string) $this->session->get('verify_email', ''));
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->users->findByEmail($email) : null;
        if ($user !== null && !$user->isEmailVerified()) {
            $this->authService->sendVerification($user);
        }
        // Ответ одинаков для любых адресов — чтобы нельзя было перебирать зарегистрированные email
        $this->flash('success', 'Если адрес зарегистрирован и не подтверждён, мы отправили новое письмо.');

        return $this->redirect('/verify-email/pending');
    }

    public function confirmEmailChange(string $token): Response
    {
        $user = $this->authService->confirmEmailChange($token);
        $this->flash($user !== null ? 'success' : 'error', $user !== null ? 'Новый email подтверждён' : 'Ссылка недействительна или устарела');

        return $this->redirect($this->auth->check() ? '/account/profile' : '/login');
    }

    // ---- пароль --------------------------------------------------------------------------

    public function showForgot(): Response
    {
        $this->meta($this->seo->page('Восстановление пароля', '', '/forgot-password', false));

        return $this->view('web/auth/forgot');
    }

    public function forgot(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email'], ['email' => 'Email']);
        $this->authService->requestPasswordReset((string) $data['email'], $request);
        $this->flash('success', 'Если такой адрес зарегистрирован, мы отправили на него ссылку для сброса пароля.');

        return $this->redirect('/forgot-password');
    }

    public function showReset(string $token): Response
    {
        $this->meta($this->seo->page('Новый пароль', '', '/reset-password', false));
        if (!$this->authService->isResetTokenValid($token)) {
            $this->flash('error', 'Ссылка для сброса недействительна или устарела. Запросите новую.');

            return $this->redirect('/forgot-password');
        }

        return $this->view('web/auth/reset', ['token' => $token]);
    }

    public function reset(Request $request, string $token): Response
    {
        $data = $this->validate($request, [
            'password' => 'required|string|min:8|max:128',
            'password_confirmation' => 'required|same:password',
        ], ['password' => 'Пароль', 'password_confirmation' => 'Подтверждение пароля']);
        $this->authService->resetPassword($token, (string) $data['password']);
        $this->flash('success', 'Пароль изменён. Войдите с новым паролем.');

        return $this->redirect('/login');
    }

    // ---- двухфакторная аутентификация ------------------------------------------------------

    public function showTwoFactor(): Response
    {
        if ($this->auth->pendingTwoFactor() === null && !$this->auth->needsTwoFactorChallenge()) {
            return $this->redirect($this->auth->check() ? '/account' : '/login');
        }
        $this->meta($this->seo->page('Код подтверждения', '', '/login/two-factor', false));

        return $this->view('web/auth/two-factor');
    }

    public function twoFactor(Request $request): Response
    {
        $code = $request->string('code');
        $pending = $this->auth->pendingTwoFactor();
        if ($pending !== null) {
            if (!$this->twoFactor->verify($pending['user'], $code)) {
                throw new ValidationException(['code' => 'Неверный код. Введите 6 цифр из приложения или код восстановления.']);
            }
            $this->auth->login($pending['user'], $request, $pending['remember'], true);
            $intended = $pending['intended'] ?? $this->session->pull('intended');

            return $this->redirect($this->safeLocal(is_string($intended) ? $intended : null, '/account'));
        }
        $user = $this->auth->user();
        if ($user !== null && $this->auth->needsTwoFactorChallenge()) {
            if (!$this->twoFactor->verify($user, $code)) {
                throw new ValidationException(['code' => 'Неверный код. Введите 6 цифр из приложения или код восстановления.']);
            }
            $this->auth->markTwoFactorPassed();
            $intended = $this->session->pull('intended');

            return $this->redirect($this->safeLocal(is_string($intended) ? $intended : null, '/admin'));
        }

        return $this->redirect('/login');
    }
}
