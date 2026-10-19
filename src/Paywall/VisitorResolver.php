<?php

declare(strict_types=1);

namespace App\Paywall;

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Определяет посетителя по cookie vid (выдаётся при первом обращении к метрируемому контенту). */
final class VisitorResolver
{
    public const COOKIE = 'vid';

    public function __construct(
        private readonly Auth $auth,
        private readonly Config $config,
    ) {
    }

    public function resolve(Request $request): Visitor
    {
        $cookie = $request->cookie(self::COOKIE);
        $isNew = false;
        if ($cookie === null || !preg_match('/^[a-f0-9]{32}$/', $cookie)) {
            $cookie = bin2hex(random_bytes(16));
            $isNew = true;
        }

        return new Visitor($cookie, $isNew, $this->auth->id());
    }

    public function attach(Response $response, Visitor $visitor, Request $request): void
    {
        if ($visitor->isNew) {
            $response->withCookie(self::COOKIE, $visitor->id, [
                'expires' => time() + 86400 * 400,
                'path' => '/',
                'secure' => $request->isSecure() || str_starts_with($this->config->appUrl(), 'https://'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
