<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Security\Session;
use App\User\User;
use App\User\UserRepository;

/**
 * Текущий пользователь запроса: вход/выход, восстановление по «запомнить меня»,
 * состояние двухфакторной проверки.
 */
final class Auth
{
    private const UID = 'uid';
    private const TWOFA_OK = 'twofa_ok';
    private const PENDING = 'pending_2fa';
    private const PENDING_TTL = 600;

    private ?User $user = null;
    private bool $resolved = false;

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly RememberMeService $remember,
        private readonly Config $config,
    ) {
    }

    public function user(): ?User
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $uid = $this->session->get(self::UID);
            if (is_int($uid) || (is_string($uid) && ctype_digit($uid))) {
                $user = $this->users->find((int) $uid);
                if ($user !== null && $user->isActive()) {
                    $this->user = $user;
                } else {
                    $this->session->forget(self::UID, self::TWOFA_OK);
                }
            }
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        return $this->user()?->id;
    }

    /** Войти по cookie «запомнить меня», если сессия не содержит пользователя. */
    public function restoreFromRemember(Request $request): void
    {
        if ($this->user() !== null) {
            return;
        }
        $cookie = $request->cookie(RememberMeService::COOKIE);
        if ($cookie === null || $cookie === '') {
            return;
        }
        $userId = $this->remember->consume($cookie);
        $user = $userId !== null ? $this->users->find($userId) : null;
        if ($user === null || !$user->isActive()) {
            $this->cookies[] = $this->forgetCookie($request);

            return;
        }
        // Вход по cookie не подтверждает второй фактор: для админки потребуется TOTP
        $this->establish($user, false);
        $this->cookies[] = $this->rememberCookie($this->remember->issue($user->id, $request), $request);
    }

    /** Завершить вход: новая сессия, запись о входе, опционально cookie «запомнить меня». */
    public function login(User $user, Request $request, bool $remember = false, bool $twoFactorVerified = false): void
    {
        $this->session->forget(self::PENDING);
        $this->establish($user, $twoFactorVerified);
        $this->users->update($user->id, ['last_login_at' => new \DateTimeImmutable(), 'last_login_ip' => $request->ip()]);
        if ($remember) {
            $this->cookies[] = $this->rememberCookie($this->remember->issue($user->id, $request), $request);
        }
    }

    private function establish(User $user, bool $twoFactorVerified): void
    {
        $this->session->regenerate();
        $this->session->set(self::UID, $user->id);
        $this->session->set(self::TWOFA_OK, $twoFactorVerified);
        $this->user = $user;
        $this->resolved = true;
    }

    public function logout(Request $request): void
    {
        $cookie = $request->cookie(RememberMeService::COOKIE);
        if ($cookie !== null) {
            $this->remember->forget($cookie);
        }
        $this->cookies[] = $this->forgetCookie($request);
        $this->session->invalidate();
        $this->user = null;
        $this->resolved = true;
    }

    // ---- двухфакторная аутентификация ----------------------------------------------------

    /** Пароль верен, но нужен код TOTP: запоминаем «ожидающий» вход. */
    public function startTwoFactorChallenge(User $user, bool $remember, ?string $intended = null): void
    {
        $this->session->set(self::PENDING, ['uid' => $user->id, 'remember' => $remember, 'at' => time(), 'intended' => $intended]);
    }

    /** @return array{user:User,remember:bool,intended:?string}|null */
    public function pendingTwoFactor(): ?array
    {
        $pending = $this->session->get(self::PENDING);
        if (!is_array($pending) || (time() - (int) ($pending['at'] ?? 0)) > self::PENDING_TTL) {
            return null;
        }
        $user = $this->users->find((int) ($pending['uid'] ?? 0));
        if ($user === null || !$user->isActive()) {
            return null;
        }

        return ['user' => $user, 'remember' => (bool) ($pending['remember'] ?? false), 'intended' => $pending['intended'] ?? null];
    }

    public function clearPendingTwoFactor(): void
    {
        $this->session->forget(self::PENDING);
    }

    /** Отметить сессию как прошедшую второй фактор (повышение уже выполненного входа). */
    public function markTwoFactorPassed(): void
    {
        $this->session->set(self::TWOFA_OK, true);
    }

    public function twoFactorPassed(): bool
    {
        return (bool) $this->session->get(self::TWOFA_OK, false);
    }

    /** Для роли обязателен TOTP, но он ещё не настроен. */
    public function needsTwoFactorSetup(): bool
    {
        $user = $this->user();

        return $user !== null && $user->role->requiresTwoFactor() && !$user->hasTwoFactor();
    }

    /** У пользователя включён TOTP, но в этой сессии он не подтверждён. */
    public function needsTwoFactorChallenge(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasTwoFactor() && !$this->twoFactorPassed();
    }

    /** Перечитать пользователя из БД (после изменения профиля). */
    public function refresh(): void
    {
        $this->resolved = false;
        $this->user = null;
    }

    /** @return list<array{name:string,value:string,options:array<string,mixed>}> */
    public function takeCookies(): array
    {
        $cookies = $this->cookies;
        $this->cookies = [];

        return $cookies;
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    private function rememberCookie(string $value, Request $request): array
    {
        return [
            'name' => RememberMeService::COOKIE,
            'value' => $value,
            'options' => [
                'expires' => time() + RememberMeService::TTL_DAYS * 86400,
                'path' => '/',
                'secure' => $request->isSecure() || str_starts_with($this->config->appUrl(), 'https://'),
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
    }

    /** @return array{name:string,value:string,options:array<string,mixed>} */
    private function forgetCookie(Request $request): array
    {
        return ['name' => RememberMeService::COOKIE, 'value' => '', 'options' => ['expires' => time() - 3600, 'path' => '/']];
    }
}
