<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Session;

final class RequireAuth implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        if (!$this->auth->check()) {
            if ($request->expectsJson()) {
                throw new HttpException(401, 'Войдите в аккаунт, чтобы продолжить');
            }
            if ($request->isMethod('GET')) {
                $this->session->set('intended', $request->uri());
            }
            $this->session->flash('info', 'Войдите, чтобы продолжить');

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
