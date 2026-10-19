<?php

declare(strict_types=1);

namespace App\Article;

use App\Support\HtmlText;

/** Время чтения: ~190 слов в минуту + 12 секунд на каждое изображение. */
final class ReadingTime
{
    private const WORDS_PER_MINUTE = 190;
    private const SECONDS_PER_IMAGE = 12;

    public static function minutes(string $html): int
    {
        $words = HtmlText::wordCount($html);
        $images = preg_match_all('/<img\b/i', $html);
        $seconds = ($words / self::WORDS_PER_MINUTE) * 60 + $images * self::SECONDS_PER_IMAGE;

        return max(1, (int) ceil($seconds / 60));
    }
}
