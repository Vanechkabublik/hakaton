<?php

declare(strict_types=1);

namespace App\Paywall;

/** Идентификатор посетителя для метрированного пейволла и аналитики. */
final class Visitor
{
    public function __construct(
        /** Случайный id из cookie vid (до 32 hex-символов) */
        public readonly string $id,
        /** Cookie нужно выдать в ответе */
        public readonly bool $isNew,
        public readonly ?int $userId,
    ) {
    }

    /** Ключи, под которыми считаются прочитанные статьи: гость и аккаунт учитываются вместе. */
    public function identities(): array
    {
        $keys = ['v:' . $this->id];
        if ($this->userId !== null) {
            $keys[] = 'u:' . $this->userId;
        }

        return $keys;
    }

    /** Основной ключ для записи. */
    public function key(): string
    {
        return $this->userId !== null ? 'u:' . $this->userId : 'v:' . $this->id;
    }
}
