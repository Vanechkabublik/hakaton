<?php

declare(strict_types=1);

namespace App\Auth;

final class YandexProfile
{
    public function __construct(
        public readonly string $id,
        public readonly string $login,
        public readonly string $email,
        public readonly string $name,
        public readonly ?string $avatarUrl,
    ) {
    }
}
