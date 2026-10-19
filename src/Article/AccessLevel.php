<?php

declare(strict_types=1);

namespace App\Article;

/**
 * Уровень доступа к материалу.
 *  free    — бесплатно для всех;
 *  digital — по подписке (любой тариф); неподписанным доступно N бесплатных статей в месяц;
 *  premium — только для подписчиков тарифа Premium, без бесплатного счётчика.
 */
enum AccessLevel: string
{
    case Free = 'free';
    case Digital = 'digital';
    case Premium = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Бесплатно',
            self::Digital => 'По подписке (счётчик бесплатных)',
            self::Premium => 'Только Premium',
        };
    }

    public function isPaid(): bool
    {
        return $this !== self::Free;
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
