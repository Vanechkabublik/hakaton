<?php

declare(strict_types=1);

namespace App\Core\Http;

/** Исходящие HTTP-запросы (Яндекс OAuth, ЮKassa). Интерфейс позволяет подменять клиент в тестах. */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @throws \RuntimeException при сетевой ошибке
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): HttpResult;
}
