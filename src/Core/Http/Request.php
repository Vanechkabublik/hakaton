<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Иммутабельное по смыслу представление HTTP-запроса.
 * Атрибуты (attributes) используются middleware и роутером для передачи данных вниз по цепочке.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $attributes = [];

    /** @var array<string,mixed>|null */
    private ?array $jsonCache = null;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,string> $cookies
     * @param array<string,mixed> $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private array $post = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $path = '/' . trim(preg_replace('#/{2,}#', '/', $path) ?? '/', '/');
        if (strlen($path) > 1 && str_ends_with($uri, '/') && !str_contains($uri, '?')) {
            $path = rtrim($path, '/');
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $post = $_POST;
        if ($method === 'POST' && isset($post['_method']) && in_array(strtoupper((string) $post['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $post['_method']);
        }

        return new self(
            $method,
            $path === '' ? '/' : $path,
            $_GET,
            $post,
            $_FILES,
            $_COOKIE,
            $_SERVER,
            (string) file_get_contents('php://input'),
        );
    }

    /**
     * Фабрика для тестов и внутренних вызовов.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $server
     * @param array<string,string> $cookies
     */
    public static function create(string $method, string $uri, array $data = [], array $server = [], array $cookies = []): self
    {
        $method = strtoupper($method);
        $parts = parse_url($uri);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $isGet = $method === 'GET' || $method === 'HEAD';
        $server += ['REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit'];

        return new self(
            $method,
            $parts['path'] ?? '/',
            $isGet ? array_merge($query, $data) : $query,
            $isGet ? [] : $data,
            [],
            $cookies,
            $server,
            ($server['CONTENT_TYPE'] ?? '') === 'application/json' ? (string) json_encode($data) : '',
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string ...$methods): bool
    {
        return in_array($this->method, array_map('strtoupper', $methods), true);
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? $this->path);
    }

    /** @return array<string,mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->json()[$key] ?? $default;
    }

    /** Значение из тела запроса (form/JSON), затем из query-строки. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->json()[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        $value = $this->input($key);

        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json());
    }

    /** @param list<string> $keys @return array<string,mixed> */
    public function only(array $keys): array
    {
        $all = $this->all();

        return array_intersect_key($all, array_flip($keys));
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->jsonCache === null) {
            $this->jsonCache = [];
            if ($this->rawBody !== '' && str_contains($this->header('Content-Type', '') ?? '', 'json')) {
                $parsed = json_decode($this->rawBody, true);
                $this->jsonCache = is_array($parsed) ? $parsed : [];
            }
        }

        return $this->jsonCache;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return null;
        }
        $upload = UploadedFile::fromArray($file);

        return $upload->isProvided() ? $upload : null;
    }

    /** @return list<UploadedFile> */
    public function files(string $key): array
    {
        $files = $this->files[$key] ?? null;
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            $single = $this->file($key);

            return $single ? [$single] : [];
        }
        $result = [];
        foreach (array_keys($files['name']) as $i) {
            $upload = UploadedFile::fromArray([
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ]);
            if ($upload->isProvided()) {
                $result[] = $upload;
            }
        }

        return $result;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        $value = $this->cookies[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        if (in_array(strtolower($name), ['content-type', 'content-length'], true)) {
            return isset($this->server[strtoupper(str_replace('-', '_', $name))])
                ? (string) $this->server[strtoupper(str_replace('-', '_', $name))]
                : $default;
        }

        return $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function referer(): ?string
    {
        return $this->header('Referer');
    }

    public function host(): string
    {
        return strtolower((string) ($this->server['HTTP_HOST'] ?? 'localhost'));
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';

        return ($https !== '' && $https !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With', '') ?? '') === 'xmlhttprequest';
    }

    public function expectsJson(): bool
    {
        if ($this->isAjax()) {
            return true;
        }
        $accept = $this->header('Accept', '') ?? '';

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** Подмена данных формы (используется в тестах и внутренних перенаправлениях). */
    public function withPost(array $post): self
    {
        $clone = clone $this;
        $clone->post = $post;

        return $clone;
    }
}
