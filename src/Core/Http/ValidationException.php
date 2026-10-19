<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/** Ошибки валидации формы; роутер превращает их в редирект назад (HTML) или 422 (JSON). */
final class ValidationException extends RuntimeException
{
    /** @param array<string,string> $errors поле => сообщение */
    public function __construct(public readonly array $errors, public readonly ?string $redirectTo = null)
    {
        parent::__construct('Validation failed');
    }

    public function first(): string
    {
        return (string) (array_values($this->errors)[0] ?? 'Проверьте введённые данные');
    }
}
