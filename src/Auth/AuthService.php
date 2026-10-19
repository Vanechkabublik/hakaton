<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLog;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\ValidationException;
use App\Core\Security\PasswordHasher;
use App\Core\Security\RateLimiter;
use App\Core\Security\Session;
use App\Notification\MailService;
use App\User\User;
use App\User\UserRepository;

/** Регистрация, вход по паролю, подтверждение email, восстановление и смена пароля. */
final class AuthService
{
    private const LOGIN_EMAIL_MAX = 5;
    private const LOGIN_IP_MAX = 30;
    private const LOGIN_WINDOW = 900;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly Auth $auth,
        private readonly EmailTokenService $tokens,
        private readonly RememberMeService $remember,
        private readonly MailService $mail,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly Session $session,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array{name:string,email:string,password:string,marketing?:bool,digest?:bool} $data
     * @throws ValidationException
     */
    public function register(array $data): User
    {
        $email = mb_strtolower(trim($data['email']));
        if ($this->users->emailExists($email)) {
            throw new ValidationException(['email' => 'Этот email уже зарегистрирован. Войдите или восстановите пароль.']);
        }
        $problem = $this->policy->validate($data['password'], $email);
        if ($problem !== null) {
            throw new ValidationException(['password' => $problem]);
        }
        $user = $this->users->createUser([
            'email' => $email,
            'name' => $data['name'],
            'password_hash' => $this->hasher->hash($data['password']),
            'marketing_opt_in' => !empty($data['marketing']),
            'digest_frequency' => !empty($data['digest']) ? 'weekly' : 'none',
        ]);
        $this->audit->record('auth.register', $user->id, 'user', $user->id);
        $this->sendVerification($user);

        return $user;
    }

    public function sendVerification(User $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }
        $token = $this->tokens->create($user, EmailTokenService::VERIFY);
        $this->mail->queue($user->email, 'Подтвердите email', 'verify-email', [
            'name' => $user->name,
            'url' => $this->config->appUrl() . '/verify-email/' . $token,
        ], $user);
    }

    /** Подтвердить email по токену из письма. Возвращает пользователя или null. */
    public function verifyEmail(string $token): ?User
    {
        $data = $this->tokens->consume($token, EmailTokenService::VERIFY);
        if ($data === null) {
            return null;
        }
        $user = $this->users->find($data['user_id']);
        if ($user === null) {
            return null;
        }
        if (!$user->isEmailVerified()) {
            $this->users->update($user->id, ['email_verified_at' => new \DateTimeImmutable()]);
            $this->audit->record('auth.email_verified', $user->id, 'user', $user->id);
            $user = $this->users->find($user->id) ?? $user;
        }

        return $user;
    }

    /** Попытка входа по email и паролю с ограничением частоты. */
    public function attempt(string $email, string $password, bool $remember, Request $request, ?string $intended = null): LoginResult
    {
        $email = mb_strtolower(trim($email));
        $emailKey = 'login:email:' . sha1($email);
        $ipKey = 'login:ip:' . $request->ip();

        if ($this->limiter->tooManyAttempts($emailKey, self::LOGIN_EMAIL_MAX) || $this->limiter->tooManyAttempts($ipKey, self::LOGIN_IP_MAX)) {
            $retry = max($this->limiter->retryAfter($emailKey), $this->limiter->retryAfter($ipKey));
            $this->audit->record('auth.login_locked', null, 'email', $email);

            return new LoginResult(LoginResult::LOCKED, null, $retry);
        }

        $user = $this->users->findByEmail($email);
        $valid = $user !== null && $user->hasPassword() && $this->hasher->verify($password, (string) $user->passwordHash);
        if ($user === null || !$user->hasPassword()) {
            $this->hasher->dummyVerify($password);
        }
        if (!$valid || $user === null) {
            $this->limiter->hit($emailKey, self::LOGIN_WINDOW);
            $this->limiter->hit($ipKey, self::LOGIN_WINDOW);
            $this->audit->record('auth.login_failed', $user?->id, 'email', $email);

            return new LoginResult(LoginResult::INVALID);
        }
        if (!$user->isActive()) {
            return new LoginResult(LoginResult::BLOCKED, $user);
        }
        if (!$user->isEmailVerified()) {
            return new LoginResult(LoginResult::UNVERIFIED, $user);
        }

        $this->limiter->clear($emailKey);
        if ($this->hasher->needsRehash((string) $user->passwordHash)) {
            $this->users->update($user->id, ['password_hash' => $this->hasher->hash($password)]);
        }

        if ($user->hasTwoFactor()) {
            $this->auth->startTwoFactorChallenge($user, $remember, $intended);

            return new LoginResult(LoginResult::TWO_FACTOR, $user);
        }
        $this->auth->login($user, $request, $remember);
        $this->audit->record('auth.login', $user->id, 'user', $user->id);

        return new LoginResult(LoginResult::OK, $user);
    }

    /** Письмо со ссылкой сброса. Ответ одинаков для существующих и несуществующих адресов. */
    public function requestPasswordReset(string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        if (!$this->limiter->attempt('reset:ip:' . $request->ip(), 10, 3600) || !$this->limiter->attempt('reset:email:' . sha1($email), 3, 3600)) {
            return;
        }
        $user = $this->users->findByEmail($email);
        if ($user === null || !$user->isActive()) {
            return;
        }
        $token = $this->tokens->create($user, EmailTokenService::RESET);
        $this->mail->queue($user->email, 'Восстановление пароля', 'reset-password', [
            'name' => $user->name,
            'url' => $this->config->appUrl() . '/reset-password/' . $token,
        ], $user);
        $this->audit->record('auth.reset_requested', $user->id, 'user', $user->id);
    }

    public function isResetTokenValid(string $token): bool
    {
        return $this->tokens->peek($token, EmailTokenService::RESET) !== null;
    }

    /** @throws ValidationException */
    public function resetPassword(string $token, string $password): User
    {
        $peek = $this->tokens->peek($token, EmailTokenService::RESET);
        $user = $peek !== null ? $this->users->find($peek['user_id']) : null;
        if ($user === null) {
            throw new ValidationException(['password' => 'Ссылка недействительна или устарела. Запросите новую.']);
        }
        $problem = $this->policy->validate($password, $user->email);
        if ($problem !== null) {
            throw new ValidationException(['password' => $problem]);
        }
        if ($this->tokens->consume($token, EmailTokenService::RESET) === null) {
            throw new ValidationException(['password' => 'Ссылка уже использована. Запросите новую.']);
        }
        $this->users->update($user->id, [
            'password_hash' => $this->hasher->hash($password),
            // ссылка из письма доказывает владение адресом
            'email_verified_at' => $user->emailVerifiedAt ?? new \DateTimeImmutable(),
        ]);
        $this->remember->forgetAll($user->id);
        $this->session->destroyUserSessions($user->id, false);
        $this->audit->record('auth.password_reset', $user->id, 'user', $user->id);

        return $this->users->find($user->id) ?? $user;
    }

    /**
     * Смена пароля из личного кабинета.
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, ?string $current, string $new): void
    {
        if ($user->hasPassword() && !$this->hasher->verify((string) $current, (string) $user->passwordHash)) {
            throw new ValidationException(['current_password' => 'Текущий пароль указан неверно']);
        }
        $problem = $this->policy->validate($new, $user->email);
        if ($problem !== null) {
            throw new ValidationException(['password' => $problem]);
        }
        $this->users->update($user->id, ['password_hash' => $this->hasher->hash($new)]);
        $this->remember->forgetAll($user->id);
        $this->session->destroyUserSessions($user->id, true);
        $this->audit->record('auth.password_changed', $user->id, 'user', $user->id);
    }

    /** Письмо подтверждения нового адреса. */
    public function requestEmailChange(User $user, string $newEmail): void
    {
        $newEmail = mb_strtolower(trim($newEmail));
        if ($this->users->emailExists($newEmail, $user->id)) {
            throw new ValidationException(['email' => 'Этот email уже используется другим аккаунтом']);
        }
        $token = $this->tokens->create($user, EmailTokenService::EMAIL_CHANGE, ['email' => $newEmail]);
        $this->users->update($user->id, ['pending_email' => $newEmail]);
        $this->mail->queue($newEmail, 'Подтвердите новый email', 'change-email', [
            'name' => $user->name,
            'url' => $this->config->appUrl() . '/confirm-email-change/' . $token,
        ], $user);
    }

    public function confirmEmailChange(string $token): ?User
    {
        $data = $this->tokens->consume($token, EmailTokenService::EMAIL_CHANGE);
        if ($data === null || empty($data['payload']['email'])) {
            return null;
        }
        $newEmail = (string) $data['payload']['email'];
        $user = $this->users->find($data['user_id']);
        if ($user === null || $this->users->emailExists($newEmail, $user->id)) {
            return null;
        }
        $this->users->update($user->id, ['email' => $newEmail, 'pending_email' => null, 'email_verified_at' => new \DateTimeImmutable()]);
        $this->audit->record('auth.email_changed', $user->id, 'user', $user->id, ['to' => $newEmail]);

        return $this->users->find($user->id);
    }
}
