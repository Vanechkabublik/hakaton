<?php

declare(strict_types=1);

namespace App\User;

enum Role: string
{
    case Reader = 'reader';
    case Author = 'author';
    case Editor = 'editor';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Reader => 'Читатель',
            self::Author => 'Автор',
            self::Editor => 'Редактор',
            self::Admin => 'Администратор',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Reader => 0,
            self::Author => 1,
            self::Editor => 2,
            self::Admin => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** Сотрудники редакции (имеют доступ в админку). */
    public function isStaff(): bool
    {
        return $this !== self::Reader;
    }

    /** Для этих ролей двухфакторная аутентификация обязательна. */
    public function requiresTwoFactor(): bool
    {
        return $this === self::Editor || $this === self::Admin;
    }
}
