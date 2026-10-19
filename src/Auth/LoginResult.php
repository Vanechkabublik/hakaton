<?php

declare(strict_types=1);

namespace App\Auth;

use App\User\User;

/** Итог попытки входа по паролю. */
final class LoginResult
{
    public const OK = 'ok';
    public const TWO_FACTOR = 'two_factor';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';
    public const UNVERIFIED = 'unverified';
    public const BLOCKED = 'blocked';

    public function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly int $retryAfter = 0,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === self::OK || $this->status === self::TWO_FACTOR;
    }
}
