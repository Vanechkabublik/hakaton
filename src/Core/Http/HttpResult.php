<?php

declare(strict_types=1);

namespace App\Core\Http;

final class HttpResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }
}
