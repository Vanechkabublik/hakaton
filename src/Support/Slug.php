<?php

declare(strict_types=1);

namespace App\Support;

/** Генерация ЧПУ-идентификаторов (slug) с транслитерацией кириллицы. */
final class Slug
{
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
    ];

    public static function make(string $text, int $maxLength = 80): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, self::MAP);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength);
            $cut = strrpos($text, '-');
            if ($cut !== false && $cut > $maxLength * 0.5) {
                $text = substr($text, 0, $cut);
            }
            $text = trim($text, '-');
        }

        return $text;
    }

    /**
     * Уникальный slug: при коллизии добавляется числовой суффикс.
     *
     * @param callable(string):bool $exists
     */
    public static function unique(string $text, callable $exists, string $fallback = 'item'): string
    {
        $base = self::make($text) ?: $fallback;
        $slug = $base;
        $i = 2;
        while ($exists($slug)) {
            $slug = $base . '-' . $i++;
            if ($i > 500) {
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $slug;
    }
}
