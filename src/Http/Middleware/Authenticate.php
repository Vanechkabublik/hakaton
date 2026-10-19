<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Глобальный: определяет пользователя (сессия / «запомнить меня») и выдаёт отложенные cookie. */
final class Authenticate implements Middleware
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        $this->auth->restoreFromRemember($request);
        $response = $next($request);
        foreach ($this->auth->takeCookies() as $cookie) {
            $response->withCookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        return $response;
    }
}
