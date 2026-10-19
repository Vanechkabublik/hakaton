<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Session;
use App\User\Gate;

/**
 * Доступ в админку: сотрудник редакции. Редакторы и админы обязаны иметь включённый TOTP
 * и подтвердить его в текущей сессии.
 */
final class RequireStaff implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            $this->session->set('intended', $request->uri());

            return Response::redirect('/login');
        }
        if (!$this->gate->allows($user, 'admin.access')) {
            throw HttpException::forbidden();
        }
        if ($this->auth->needsTwoFactorSetup()) {
            $this->session->flash('warning', 'Для доступа в админку необходимо включить двухфакторную аутентификацию.');

            return Response::redirect('/account/two-factor');
        }
        if ($this->auth->needsTwoFactorChallenge()) {
            $this->session->set('intended', $request->uri());

            return Response::redirect('/login/two-factor');
        }
        $response = $next($request);

        return $response->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
