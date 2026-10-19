<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Core\Validation\Validator;
use App\Seo\PageMeta;
use App\User\User;

abstract class Controller
{
    private ControllerContext $ctx;

    public function setContext(ControllerContext $ctx): void
    {
        $this->ctx = $ctx;
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        $this->ctx->composer->compose($this->ctx->requestContext->request());

        return Response::html($this->ctx->view->render($template, $data), $status);
    }

    /** Отрисовать шаблон в строку (HTML-фрагменты для AJAX). */
    protected function fragment(string $template, array $data = []): string
    {
        $this->ctx->composer->compose($this->ctx->requestContext->request());

        return $this->ctx->view->render($template, $data);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /** @param array<string,scalar> $params */
    protected function redirectRoute(string $name, array $params = [], array $query = []): Response
    {
        return Response::redirect($this->ctx->router->url($name, $params, $query));
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        return Response::redirect($this->safeLocal($request->referer(), $fallback));
    }

    /** Пропускает только локальные пути: защита от open redirect. */
    protected function safeLocal(?string $url, string $fallback = '/'): string
    {
        if ($url === null || $url === '') {
            return $fallback;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $fallback;
        }
        if (isset($parts['host'])) {
            $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $current = $this->ctx->requestContext->request()?->host();
            if ($current === null || $host !== $current) {
                return $fallback;
            }
            $url = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return $fallback;
        }

        return $url;
    }

    protected function flash(string $type, string $message): void
    {
        $this->ctx->session->flash($type, $message);
    }

    protected function user(): ?User
    {
        return $this->ctx->auth->user();
    }

    protected function requireUser(): User
    {
        return $this->ctx->auth->user() ?? throw new HttpException(401);
    }

    protected function meta(PageMeta $meta): void
    {
        $this->ctx->view->share('meta', $meta);
    }

    /**
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed>
     * @throws ValidationException
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        $validator = Validator::make($request->all(), $rules, $labels);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    protected function notFound(string $message = ''): never
    {
        throw HttpException::notFound($message);
    }
}
