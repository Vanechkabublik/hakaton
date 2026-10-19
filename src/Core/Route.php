<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

final class Route
{
    public readonly string $regex;

    /** @var list<string> */
    private array $middleware;

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly array|Closure $handler,
        array $middleware,
        private readonly Router $router,
    ) {
        $this->middleware = $middleware;
        $this->regex = '#^' . preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern
        ) . '$#u';
    }

    public function name(string $name): self
    {
        $this->router->register($name, $this);

        return $this;
    }

    /** Добавить middleware маршрута: 'auth', 'rate:login,5,900', 'role:admin'. */
    public function middleware(string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);

        return $this;
    }

    /** @return list<string> */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    /** @return array<string,string>|null параметры маршрута или null, если путь не совпал */
    public function matches(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode($value);
            }
        }

        return $params;
    }
}
