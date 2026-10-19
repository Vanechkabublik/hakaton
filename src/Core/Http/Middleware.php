<?php

declare(strict_types=1);

namespace App\Core\Http;

interface Middleware
{
    /**
     * @param callable(Request):Response $next
     * @param list<string> $args аргументы из строки маршрута: 'rate:login,5,900' => ['login','5','900']
     */
    public function handle(Request $request, callable $next, array $args = []): Response;
}
