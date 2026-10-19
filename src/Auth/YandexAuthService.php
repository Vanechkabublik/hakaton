<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLog;
use App\Core\Http\Request;
use App\Core\Security\Crypto;
use App\Core\Security\Session;
use App\User\User;
use App\User\UserRepository;

/** Сценарии Яндекс ID: вход/регистрация в один клик, привязка и отвязка в профиле. */
final class YandexAuthService
{
    private const SESSION_KEY = 'oauth_yandex';
    private const STATE_TTL = 600;
    private const PROVIDER = 'yandex';

    public function __construct(
        private readonly YandexOAuth $yandex,
        private readonly OAuthIdentityRepository $identities,
        private readonly UserRepository $users,
        private readonly Auth $auth,
        private readonly Session $session,
        private readonly AuditLog $audit,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->yandex->isConfigured();
    }

    /** Начать авторизацию: вернуть URL Яндекса и запомнить state в сессии. */
    public function begin(string $mode, ?string $intended = null, ?int $linkUserId = null): string
    {
        $state = Crypto::token(24);
        $this->session->set(self::SESSION_KEY, [
            'state' => $state,
            'mode' => $mode,
            'intended' => $intended,
            'uid' => $linkUserId,
            'at' => time(),
        ]);

        return $this->yandex->authorizeUrl($state);
    }

    /**
     * Обработать возврат с Яндекса.
     *
     * @return array{status:string,message:string,redirect:?string}
     */
    public function handleCallback(Request $request): array
    {
        $saved = $this->session->pull(self::SESSION_KEY);
        $state = $request->string('state');
        if (!is_array($saved) || $state === '' || !hash_equals((string) $saved['state'], $state) || (time() - (int) $saved['at']) > self::STATE_TTL) {
            return $this->fail('Сессия авторизации истекла. Попробуйте ещё раз.');
        }
        if ($request->string('error') !== '' || $request->string('code') === '') {
            return $this->fail('Вход через Яндекс отменён.');
        }
        try {
            $profile = $this->yandex->fetchProfile($request->string('code'));
        } catch (OAuthException $e) {
            return $this->fail($e->getMessage());
        }

        $intended = is_string($saved['intended'] ?? null) ? $saved['intended'] : null;

        return ($saved['mode'] ?? 'login') === 'link'
            ? $this->link((int) ($saved['uid'] ?? 0), $profile)
            : $this->login($profile, $request, $intended);
    }

    public function unlink(User $user): void
    {
        if (!$user->hasPassword()) {
            throw new \DomainException('Сначала задайте пароль, иначе вы потеряете доступ к аккаунту.');
        }
        $this->identities->unlink($user->id, self::PROVIDER);
        $this->audit->record('oauth.unlinked', $user->id, 'user', $user->id, ['provider' => self::PROVIDER]);
    }

    public function isLinked(User $user): bool
    {
        return $this->identities->findForUser($user->id, self::PROVIDER) !== null;
    }

    /** @return array<string,mixed>|null */
    public function identityFor(User $user): ?array
    {
        return $this->identities->findForUser($user->id, self::PROVIDER);
    }

    /** @return array{status:string,message:string,redirect:?string} */
    private function link(int $userId, YandexProfile $profile): array
    {
        $current = $this->auth->user();
        if ($current === null || $current->id !== $userId) {
            return $this->fail('Для привязки нужно войти в аккаунт.');
        }
        $existing = $this->identities->findUserId(self::PROVIDER, $profile->id);
        if ($existing !== null && $existing !== $current->id) {
            return $this->fail('Этот Яндекс ID уже привязан к другому аккаунту.', '/account/profile');
        }
        if ($existing === null) {
            $this->identities->link($current->id, self::PROVIDER, $profile->id, $profile->login, $profile->email);
            $this->audit->record('oauth.linked', $current->id, 'user', $current->id, ['provider' => self::PROVIDER]);
        }

        return ['status' => 'linked', 'message' => 'Яндекс ID привязан к аккаунту.', 'redirect' => '/account/profile'];
    }

    /** @return array{status:string,message:string,redirect:?string} */
    private function login(YandexProfile $profile, Request $request, ?string $intended): array
    {
        $userId = $this->identities->findUserId(self::PROVIDER, $profile->id);
        $user = $userId !== null ? $this->users->find($userId) : null;

        if ($user === null) {
            $user = $this->users->findByEmail($profile->email);
            if ($user !== null) {
                // Привязываем по совпадению email: Яндекс подтверждает адрес.
                // Если локальный адрес не был подтверждён, сбрасываем пароль (защита от предварительного захвата аккаунта).
                if (!$user->isEmailVerified()) {
                    $this->users->update($user->id, ['email_verified_at' => new \DateTimeImmutable(), 'password_hash' => null]);
                }
                $this->identities->link($user->id, self::PROVIDER, $profile->id, $profile->login, $profile->email);
                $this->audit->record('oauth.linked', $user->id, 'user', $user->id, ['provider' => self::PROVIDER, 'by' => 'email']);
            } else {
                $user = $this->users->createUser([
                    'email' => $profile->email,
                    'name' => $profile->name,
                    'password_hash' => null,
                    'email_verified' => true,
                    'avatar_path' => $profile->avatarUrl,
                ]);
                $this->identities->link($user->id, self::PROVIDER, $profile->id, $profile->login, $profile->email);
                $this->audit->record('auth.register', $user->id, 'user', $user->id, ['via' => 'yandex']);
            }
            $user = $this->users->find($user->id) ?? $user;
        }

        if (!$user->isActive()) {
            return $this->fail('Аккаунт заблокирован. Обратитесь в поддержку.');
        }
        if ($user->hasTwoFactor()) {
            $this->auth->startTwoFactorChallenge($user, false, $intended);

            return ['status' => 'two_factor', 'message' => '', 'redirect' => '/login/two-factor'];
        }
        $this->auth->login($user, $request, true);
        $this->audit->record('auth.login', $user->id, 'user', $user->id, ['via' => 'yandex']);

        return ['status' => 'logged_in', 'message' => 'Вы вошли через Яндекс ID.', 'redirect' => $intended];
    }

    /** @return array{status:string,message:string,redirect:?string} */
    private function fail(string $message, ?string $redirect = null): array
    {
        return ['status' => 'error', 'message' => $message, 'redirect' => $redirect];
    }
}
