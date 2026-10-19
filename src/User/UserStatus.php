<?php

declare(strict_types=1);

namespace App\User;

enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';

    public function label(): string
    {
        return $this === self::Active ? 'Активен' : 'Заблокирован';
    }
}
