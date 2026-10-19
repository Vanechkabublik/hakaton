<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Http\Request;

/** Защита от CSRF: токен привязан к сессии, передаётся в поле _token или заголовке X-CSRF-Token. */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) < 32) {
            $token = Crypto::token(32);
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function isValid(Request $request): bool
    {
        $expected = $this->session->get(self::KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $given = $request->header('X-CSRF-Token') ?? $request->post('_token');

        return is_string($given) && hash_equals($expected, $given);
    }
}
