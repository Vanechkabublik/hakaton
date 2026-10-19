<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use Closure;
use InvalidArgumentException;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string,Route> */
    private array $named = [];

    /** @var list<array{prefix:string,middleware:list<string>}> */
    private array $groups = [];

    public function get(string $path, array|Closure $handler): Route
    {
        return $this->add(['GET', 'HEAD'], $path, $handler);
    }

    public function post(string $path, array|Closure $handler): Route
    {
        return $this->add(['POST'], $path, $handler);
    }

    public function put(string $path, array|Closure $handler): Route
    {
        return $this->add(['PUT'], $path, $handler);
    }

    public function delete(string $path, array|Closure $handler): Route
    {
        return $this->add(['DELETE'], $path, $handler);
    }

    /**
     * @param array{prefix?:string,middleware?:list<string>} $attributes
     */
    public function group(array $attributes, Closure $callback): void
    {
        $this->groups[] = [
            'prefix' => $attributes['prefix'] ?? '',
            'middleware' => $attributes['middleware'] ?? [],
        ];
        $callback($this);
        array_pop($this->groups);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $path, array|Closure $handler): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groups as $group) {
            $prefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }
        $path = '/' . trim($prefix . $path, '/');
        $route = new Route($methods, $path === '' ? '/' : $path, $handler, $middleware, $this);
        $this->routes[] = $route;

        return $route;
    }

    /** @internal вызывается Route::name() */
    public function register(string $name, Route $route): void
    {
        $this->named[$name] = $route;
    }

    /**
     * @return array{0:Route,1:array<string,string>}
     * @throws HttpException 404 или 405
     */
    public function match(Request $request): array
    {
        $allowed = [];
        $method = $request->method();
        foreach ($this->routes as $route) {
            $params = $route->matches($request->path());
            if ($params === null) {
                continue;
            }
            if (in_array($method, $route->methods, true)) {
                return [$route, $params];
            }
            $allowed = array_merge($allowed, $route->methods);
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Метод не поддерживается', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw HttpException::notFound();
    }

    /**
     * @param array<string,scalar> $params
     * @param array<string,mixed> $query
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Маршрут «{$name}» не найден");
        $path = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', function (array $m) use ($params, $name): string {
            if (!array_key_exists($m[1], $params)) {
                throw new InvalidArgumentException("Не передан параметр {$m[1]} для маршрута {$name}");
            }

            return str_replace('%2F', '/', rawurlencode((string) $params[$m[1]]));
        }, $route->pattern) ?? $route->pattern;

        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
