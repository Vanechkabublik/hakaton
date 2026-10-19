<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Форматирование дат, чисел и денег для русскоязычного интерфейса. */
final class Format
{
    private const MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    private const MONTHS_NOMINATIVE = [
        1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель', 5 => 'май', 6 => 'июнь',
        7 => 'июль', 8 => 'август', 9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь',
    ];

    private static string $timezone = 'Europe/Moscow';

    public static function setTimezone(string $timezone): void
    {
        self::$timezone = $timezone;
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::$timezone);
    }

    public static function local(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(self::timezone());
    }

    /** «19 октября 2026» */
    public static function date(?DateTimeInterface $date, bool $withYear = true): string
    {
        if ($date === null) {
            return '—';
        }
        $d = self::local($date);

        return $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n')] . ($withYear ? ' ' . $d->format('Y') : '');
    }

    /** «19 октября 2026, 14:05» */
    public static function dateTime(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        return self::date($date) . ', ' . self::local($date)->format('H:i');
    }

    /** «19.10.2026 14:05» — компактный вид для таблиц админки. */
    public static function short(?DateTimeInterface $date): string
    {
        return $date === null ? '—' : self::local($date)->format('d.m.Y H:i');
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS_NOMINATIVE[$month] ?? '';
    }

    /** «5 минут назад», «вчера», либо дата. */
    public static function ago(?DateTimeInterface $date, ?DateTimeInterface $now = null): string
    {
        if ($date === null) {
            return '—';
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = $now->getTimestamp() - $date->getTimestamp();
        if ($seconds < 0) {
            return self::date($date);
        }

        return match (true) {
            $seconds < 60 => 'только что',
            $seconds < 3600 => ($m = intdiv($seconds, 60)) . ' ' . self::plural($m, ['минуту', 'минуты', 'минут']) . ' назад',
            $seconds < 86400 => ($h = intdiv($seconds, 3600)) . ' ' . self::plural($h, ['час', 'часа', 'часов']) . ' назад',
            $seconds < 172800 => 'вчера в ' . self::local($date)->format('H:i'),
            $seconds < 86400 * 7 => ($d = intdiv($seconds, 86400)) . ' ' . self::plural($d, ['день', 'дня', 'дней']) . ' назад',
            default => self::date($date, (int) self::local($date)->format('Y') !== (int) self::local($now)->format('Y')),
        };
    }

    /**
     * Склонение по числу: plural(5, ['статья','статьи','статей']) => «статей».
     *
     * @param array{0:string,1:string,2:string} $forms
     */
    public static function plural(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $forms[2];
        }
        if ($n1 > 1 && $n1 < 5) {
            return $forms[1];
        }
        if ($n1 === 1) {
            return $forms[0];
        }

        return $forms[2];
    }

    public static function number(int|float $n, int $decimals = 0): string
    {
        return number_format((float) $n, $decimals, ',', "\u{00A0}");
    }

    /** Сумма в копейках → «1 290 ₽». */
    public static function money(int $kopecks, bool $forceDecimals = false): string
    {
        $rubles = intdiv(abs($kopecks), 100);
        $cents = abs($kopecks) % 100;
        $sign = $kopecks < 0 ? '−' : '';
        $text = number_format($rubles, 0, ',', "\u{00A0}");
        if ($cents > 0 || $forceDecimals) {
            $text .= ',' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        }

        return $sign . $text . "\u{00A0}₽";
    }

    public static function bytes(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1, ',', '')) . ' ' . $units[$i];
    }

    public static function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '') . '%';
    }

    /** «3 мин чтения». */
    public static function readingTime(int $minutes): string
    {
        return max(1, $minutes) . ' мин чтения';
    }
}
