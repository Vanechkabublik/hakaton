<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Account;

use App\Auth\Auth;
use App\Auth\RememberMeService;
use App\Auth\TwoFactorService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Core\Security\PasswordHasher;
use App\Core\Security\Session;
use App\Http\Controller;
use App\Seo\SeoService;

/** Безопасность аккаунта: двухфакторная аутентификация (TOTP) и активные сессии. */
final class SecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly PasswordHasher $hasher,
        private readonly RememberMeService $remember,
        private readonly SeoService $seo,
    ) {
    }

    public function show(): Response
    {
        $user = $this->requireUser();
        $this->meta($this->seo->page('Безопасность', '', '/account/two-factor', false));
        $setup = $user->hasTwoFactor() ? null : $this->twoFactor->beginSetup($user);
        $recoveryCodes = $this->session->pull('new_recovery_codes');

        return $this->view('web/account/security', [
            'section' => 'security',
            'setup' => $setup,
            'recoveryCodes' => is_array($recoveryCodes) ? $recoveryCodes : null,
            'recoveryLeft' => $user->hasTwoFactor() ? $this->twoFactor->remainingRecoveryCodes($user) : 0,
            'required' => $user->role->requiresTwoFactor(),
            'sessions' => $this->session->listUserSessions($user->id),
            'currentHash' => $this->session->currentHash(),
        ]);
    }

    public function enable(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user->hasTwoFactor()) {
            return $this->redirect('/account/two-factor');
        }
        $codes = $this->twoFactor->confirmSetup($user, $request->string('code'));
        $this->auth->markTwoFactorPassed();
        $this->session->set('new_recovery_codes', $codes);
        $this->flash('success', 'Двухфакторная аутентификация включена. Сохраните коды восстановления.');

        return $this->redirect('/account/two-factor');
    }

    public function disable(Request $request): Response
    {
        $user = $this->requireUser();
        if ($user->role->requiresTwoFactor()) {
            throw new \DomainException('Для вашей роли двухфакторная аутентификация обязательна');
        }
        if ($user->hasPassword() && !$this->hasher->verify($request->string('password'), (string) $user->passwordHash)) {
            throw new ValidationException(['password' => 'Пароль указан неверно']);
        }
        $this->twoFactor->disable($user);
        $this->flash('success', 'Двухфакторная аутентификация отключена');

        return $this->redirect('/account/two-factor');
    }

    public function regenerate(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$user->hasTwoFactor()) {
            return $this->redirect('/account/two-factor');
        }
        if ($user->hasPassword() && !$this->hasher->verify($request->string('password'), (string) $user->passwordHash)) {
            throw new ValidationException(['password' => 'Пароль указан неверно']);
        }
        $this->session->set('new_recovery_codes', $this->twoFactor->regenerateRecoveryCodes($user));

        return $this->redirect('/account/two-factor');
    }

    public function logoutOthers(): Response
    {
        $user = $this->requireUser();
        $this->session->destroyUserSessions($user->id, true);
        $this->remember->forgetAll($user->id);
        $this->flash('success', 'Вы вышли на всех остальных устройствах');

        return $this->redirect('/account/two-factor');
    }
}
