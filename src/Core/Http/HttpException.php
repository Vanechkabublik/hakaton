<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/** Исключение с HTTP-статусом: обрабатывается ErrorHandler и превращается в страницу ошибки/JSON. */
class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        /** @var array<string,string> */
        public readonly array $headers = [],
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function tooManyRequests(int $retryAfter, string $message = ''): self
    {
        return new self(429, $message !== '' ? $message : 'Слишком много запросов. Попробуйте позже.', ['Retry-After' => (string) $retryAfter]);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Некорректный запрос',
            401 => 'Требуется авторизация',
            403 => 'Доступ запрещён',
            404 => 'Страница не найдена',
            419 => 'Сессия истекла. Обновите страницу и попробуйте снова.',
            429 => 'Слишком много запросов',
            default => 'Ошибка сервера',
        };
    }
}
