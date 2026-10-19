<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\RateLimiter;

/** `rate:comments,10,60` — не больше 10 запросов за 60 секунд на пользователя (или IP для гостей). */
final class RateLimit implements Middleware
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Auth $auth,
    ) {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        [$name, $max, $window] = [$args[0] ?? 'default', (int) ($args[1] ?? 30), (int) ($args[2] ?? 60)];
        $who = $this->auth->id() !== null ? 'u' . $this->auth->id() : 'ip' . $request->ip();
        $key = "rate:{$name}:{$who}";
        if (!$this->limiter->attempt($key, $max, $window)) {
            throw HttpException::tooManyRequests(max(1, $this->limiter->retryAfter($key)));
        }

        return $next($request);
    }
}
