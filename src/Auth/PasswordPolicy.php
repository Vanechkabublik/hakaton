<?php

declare(strict_types=1);

namespace App\Auth;

/** Требования к паролю. */
final class PasswordPolicy
{
    private const COMMON = [
        '12345678', '123456789', '1234567890', 'qwertyui', 'qwertyuiop', 'password', 'password1', 'iloveyou',
        '11111111', '00000000', 'qwerty123', 'йцукенгш', '87654321', 'abc12345', 'admin123', 'letmein1',
    ];

    /** @return string|null текст ошибки или null, если пароль подходит */
    public function validate(string $password, string $email = ''): ?string
    {
        $length = mb_strlen($password);
        if ($length < 8) {
            return 'Пароль должен быть не короче 8 символов';
        }
        if ($length > 128) {
            return 'Пароль слишком длинный (максимум 128 символов)';
        }
        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            return 'Слишком простой пароль — выберите другой';
        }
        if ($email !== '' && mb_strtolower($password) === mb_strtolower($email)) {
            return 'Пароль не должен совпадать с email';
        }
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            return 'Пароль должен содержать буквы и цифры';
        }

        return null;
    }
}
