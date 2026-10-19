<?php

declare(strict_types=1);

namespace App\Paywall;

/** Результат проверки доступа к статье. */
final class AccessDecision
{
    public const FREE = 'free';
    public const STAFF = 'staff';
    public const SUBSCRIBER = 'subscriber';
    public const METERED = 'metered';
    public const LOCKED = 'locked';

    public function __construct(
        public readonly string $reason,
        public readonly bool $allowed,
        /** Сколько бесплатных статей осталось в этом месяце (для metered) */
        public readonly int $remaining = 0,
        public readonly int $limit = 0,
        /** Для закрытых: требуется именно Premium */
        public readonly bool $needsPremium = false,
    ) {
    }

    public function isLocked(): bool
    {
        return !$this->allowed;
    }

    public function showCounter(): bool
    {
        return $this->reason === self::METERED && $this->allowed;
    }
}
