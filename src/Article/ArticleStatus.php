<?php

declare(strict_types=1);

namespace App\Article;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Pending => 'На модерации',
            self::Scheduled => 'Запланирована',
            self::Published => 'Опубликована',
            self::Archived => 'В архиве',
        };
    }

    /** CSS-модификатор бейджа. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Pending => 'warning',
            self::Scheduled => 'info',
            self::Published => 'success',
            self::Archived => 'muted',
        };
    }

    /**
     * Допустимые переходы редакционного процесса:
     * черновик → на модерации → опубликовано → архив (+ отложенная публикация и возвраты).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Scheduled, self::Published],
            self::Pending => [self::Draft, self::Scheduled, self::Published],
            self::Scheduled => [self::Draft, self::Pending, self::Published],
            self::Published => [self::Draft, self::Archived],
            self::Archived => [self::Draft, self::Published],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
