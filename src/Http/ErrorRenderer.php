<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Core\Logger;
use App\Core\Security\Session;
use App\Core\View\View;
use Throwable;

/** Превращает исключения в ответы: страницы ошибок, JSON, редиректы с ошибками валидации. */
final class ErrorRenderer
{
    public function __construct(
        private readonly View $view,
        private readonly ViewComposer $composer,
        private readonly Session $session,
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            return $this->validation($e, $request);
        }
        if ($e instanceof \DomainException) {
            return $this->userError($e->getMessage(), $request);
        }
        if ($e instanceof HttpException) {
            if ($e->status >= 500) {
                $this->logger->error($e->getMessage(), ['exception' => $e, 'uri' => $request->uri()]);
            }

            return $this->httpError($e->status, $e->getMessage(), $request, $e->headers);
        }
        $this->logger->error('Необработанное исключение', ['exception' => $e, 'uri' => $request->uri(), 'method' => $request->method()]);
        $message = $this->config->debug() ? $e::class . ': ' . $e->getMessage() : 'Что-то пошло не так. Мы уже разбираемся.';

        return $this->httpError(500, $message, $request, [], $this->config->debug() ? $e->getTraceAsString() : null);
    }

    private function validation(ValidationException $e, Request $request): Response
    {
        if ($request->expectsJson()) {
            return Response::json(['ok' => false, 'message' => $e->first(), 'errors' => $e->errors], 422);
        }
        $this->session->flashInput($request->all(), $e->errors);
        $this->session->flash('error', $e->first());
        $target = $e->redirectTo ?? $this->localReferer($request) ?? $request->path();

        return Response::redirect($target);
    }

    /** Ожидаемая бизнес-ошибка (DomainException): показываем пользователю как есть. */
    private function userError(string $message, Request $request): Response
    {
        if ($request->expectsJson()) {
            return Response::json(['ok' => false, 'message' => $message], 422);
        }
        $this->session->flash('error', $message);

        return Response::redirect($this->localReferer($request) ?? '/');
    }

    private function localReferer(Request $request): ?string
    {
        $referer = $request->referer();
        if ($referer === null) {
            return null;
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || (isset($parts['host']) && strtolower($parts['host']) !== explode(':', $request->host())[0])) {
            return null;
        }

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** @param array<string,string> $headers */
    private function httpError(int $status, string $message, Request $request, array $headers, ?string $trace = null): Response
    {
        if ($request->expectsJson()) {
            $response = Response::json(['ok' => false, 'message' => $message], $status);
        } else {
            try {
                $this->composer->compose($request);
                $html = $this->view->render('errors/http', ['status' => $status, 'message' => $message, 'trace' => $trace]);
            } catch (Throwable) {
                $html = '<!doctype html><meta charset="utf-8"><title>Ошибка ' . $status . '</title><body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem"><h1>'
                    . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="/">На главную</a></p>';
            }
            $response = Response::html($html, $status);
        }
        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }
        if ($status >= 400) {
            $response->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
