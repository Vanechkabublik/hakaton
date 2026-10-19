<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\User\Gate;

/** `can:users.manage` — проверка возможности по таблице прав Gate. */
final class RequireAbility implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        foreach ($args as $ability) {
            if (!$this->gate->allows($this->auth->user(), $ability)) {
                throw HttpException::forbidden();
            }
        }

        return $next($request);
    }
}
