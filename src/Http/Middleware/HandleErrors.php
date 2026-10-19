<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\ErrorRenderer;
use Throwable;

/** Ловит исключения нижележащих слоёв и превращает их в ответы, чтобы заголовки безопасности добавлялись и к ошибкам. */
final class HandleErrors implements Middleware
{
    public function __construct(private readonly ErrorRenderer $renderer)
    {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            return $this->renderer->render($e, $request);
        }
    }
}
