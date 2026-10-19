<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Auth\YandexAuthService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Session;
use App\Http\Controller;

/** Вход через Яндекс ID (OAuth) в один клик. Привязка/отвязка — в разделе «Профиль». */
final class OAuthController extends Controller
{
    public function __construct(
        private readonly YandexAuthService $yandex,
        private readonly Session $session,
    ) {
    }

    public function redirect(Request $request): Response
    {
        if (!$this->yandex->isConfigured()) {
            $this->flash('error', 'Вход через Яндекс пока не настроен.');

            return Response::redirect('/login');
        }
        $intended = $this->session->get('intended');

        return Response::redirect($this->yandex->begin('login', is_string($intended) ? $intended : null));
    }

    public function callback(Request $request): Response
    {
        $result = $this->yandex->handleCallback($request);
        if ($result['status'] === 'error') {
            $this->flash('error', $result['message']);

            return Response::redirect($result['redirect'] ?? '/login');
        }
        if ($result['message'] !== '') {
            $this->flash('success', $result['message']);
        }
        if ($result['status'] === 'two_factor') {
            return Response::redirect('/login/two-factor');
        }
        $this->session->forget('intended');

        return Response::redirect($this->safeLocal($result['redirect'], '/account'));
    }
}
