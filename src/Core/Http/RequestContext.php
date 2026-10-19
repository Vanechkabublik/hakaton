<?php

declare(strict_types=1);

namespace App\Core\Http;

/** Держит текущий запрос для сервисов, которым нужен IP/User-Agent (аудит, логи). В CLI пуст. */
final class RequestContext
{
    private ?Request $request = null;

    public function set(?Request $request): void
    {
        $this->request = $request;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function ip(): ?string
    {
        return $this->request?->ip();
    }
}
