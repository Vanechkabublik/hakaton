<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Http\HttpClient;
use App\Settings\SettingsService;

/** Клиент Яндекс ID (OAuth 2.0, authorization code flow). */
final class YandexOAuth
{
    private const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';
    private const TOKEN_URL = 'https://oauth.yandex.ru/token';
    private const INFO_URL = 'https://login.yandex.ru/info?format=json';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly HttpClient $http,
        private readonly Config $config,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('yandex.client_id') !== '' && $this->settings->get('yandex.client_secret') !== '';
    }

    public function redirectUri(): string
    {
        return $this->config->appUrl() . '/auth/yandex/callback';
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->get('yandex.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => 'login:email login:info login:avatar',
        ]);
    }

    /** @throws OAuthException */
    public function fetchProfile(string $code): YandexProfile
    {
        try {
            $token = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->settings->get('yandex.client_id'),
                'client_secret' => $this->settings->get('yandex.client_secret'),
            ]));
            $accessToken = (string) ($token->json()['access_token'] ?? '');
            if (!$token->ok() || $accessToken === '') {
                throw new OAuthException('Яндекс не подтвердил авторизацию');
            }
            $info = $this->http->request('GET', self::INFO_URL, ['Authorization' => 'OAuth ' . $accessToken]);
        } catch (\RuntimeException $e) {
            if ($e instanceof OAuthException) {
                throw $e;
            }
            throw new OAuthException('Не удалось связаться с Яндексом, попробуйте позже', 0, $e);
        }
        $data = $info->json();
        if (!$info->ok() || empty($data['id'])) {
            throw new OAuthException('Не удалось получить профиль Яндекс ID');
        }
        $email = (string) ($data['default_email'] ?? ($data['emails'][0] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OAuthException('Яндекс не передал адрес почты. Разрешите доступ к email и повторите.');
        }
        $name = trim((string) ($data['real_name'] ?? $data['display_name'] ?? $data['login'] ?? ''));
        $avatar = !empty($data['default_avatar_id']) && empty($data['is_avatar_empty'])
            ? 'https://avatars.yandex.net/get-yapic/' . rawurlencode((string) $data['default_avatar_id']) . '/islands-200'
            : null;

        return new YandexProfile((string) $data['id'], (string) ($data['login'] ?? ''), mb_strtolower($email), $name !== '' ? $name : (string) $data['login'], $avatar);
    }
}
