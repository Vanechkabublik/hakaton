<?php

declare(strict_types=1);

namespace App\Core\Http;

class Response
{
    /** @var array<string,string> */
    protected array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    protected array $cookies = [];

    public function __construct(
        protected string $body = '',
        protected int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[$this->normalize($name)] = (string) $value;
        }
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($text, $status, ['Content-Type' => $type . '; charset=utf-8']);
    }

    public static function xml(string $xml, string $type = 'application/xml'): self
    {
        return new self($xml, 200, ['Content-Type' => $type . '; charset=utf-8']);
    }

    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function download(string $content, string $filename, string $type = 'application/octet-stream'): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

        return new self($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function withBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$this->normalize($name)] ?? null;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[$this->normalize($name)] = $value;

        return $this;
    }

    public function withoutHeader(string $name): static
    {
        unset($this->headers[$this->normalize($name)]);

        return $this;
    }

    /**
     * @param array<string,mixed> $options expires, path, domain, secure, httponly, samesite
     */
    public function withCookie(string $name, string $value, array $options = []): static
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function forgetCookie(string $name): static
    {
        return $this->withCookie($name, '', ['expires' => time() - 3600]);
    }

    /** @return list<array{name:string,value:string,options:array<string,mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                $options = $cookie['options'] + ['path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'];
                setcookie($cookie['name'], $cookie['value'], $options);
            }
        }
        $this->sendBody();
    }

    protected function sendBody(): void
    {
        echo $this->body;
    }

    private function normalize(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
