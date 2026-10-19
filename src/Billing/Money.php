<?php

declare(strict_types=1);

namespace App\Billing;

/** Денежные суммы хранятся целыми копейками; здесь — преобразования для API платёжной системы. */
final class Money
{
    public static function toDecimal(int $kopecks): string
    {
        return sprintf('%s%d.%02d', $kopecks < 0 ? '-' : '', intdiv(abs($kopecks), 100), abs($kopecks) % 100);
    }

    /** «299.00» → 29900. */
    public static function fromDecimal(string|int|float $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** Рубли из формы («299» или «299,50») → копейки. */
    public static function fromInput(string $value): int
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        return is_numeric($value) ? (int) round((float) $value * 100) : 0;
    }

    /** Копейки → «299» либо «299.50» для полей формы. */
    public static function toInput(int $kopecks): string
    {
        return $kopecks % 100 === 0 ? (string) intdiv($kopecks, 100) : self::toDecimal($kopecks);
    }
}
