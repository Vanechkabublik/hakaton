<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;

/** Глобальный: проверка CSRF-токена для всех небезопасных методов, кроме явно исключённых путей. */
final class VerifyCsrf implements Middleware
{
    /** Пути без CSRF: вебхуки внешних сервисов и анонимные счётчики. */
    private const EXCEPT = ['/webhooks/', '/api/track/'];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        if (!$request->isSafeMethod() && !$this->isExcluded($request->path()) && !$this->csrf->isValid($request)) {
            throw new HttpException(419);
        }

        return $next($request);
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCEPT as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
