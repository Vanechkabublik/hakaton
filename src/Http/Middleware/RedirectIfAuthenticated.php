<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        return $this->auth->check() ? Response::redirect('/account') : $next($request);
    }
}
