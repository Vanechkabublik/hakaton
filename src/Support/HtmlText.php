<?php

declare(strict_types=1);

namespace App\Support;

/** Преобразования HTML → текст (для текстовой части писем и сниппетов). */
final class HtmlText
{
    /** Простейшее приведение письма к text/plain: ссылки превращаются в «текст (url)». */
    public static function toPlain(string $html): string
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $text = trim(strip_tags($m[2]));

                return $text === '' || $text === $m[1] ? $m[1] : $text . ' (' . $m[1] . ')';
            },
            $html
        ) ?? $html;
        $html = preg_replace('#<br\s*/?>|</p>|</div>|</tr>|</h[1-6]>|</li>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /** Чистый текст без тегов (для поиска, чтения, excerpt). */
    public static function strip(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</?(p|div|br|li|h[1-6]|blockquote|tr)\b[^>]*>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Срезает хвостовую пунктуацию и пробелы. Не rtrim() с маской: тот работает по байтам и «съедает»
     * последний байт кириллической «р» (D1 80) и других букв, если в маске есть многобайтовое тире.
     */
    public static function trimTrailingPunctuation(string $text): string
    {
        return preg_replace('/[\s,.;:–—-]+$/u', '', $text) ?? $text;
    }

    public static function wordCount(string $html): int
    {
        $text = self::strip($html);

        return $text === '' ? 0 : preg_match_all('/[\p{L}\p{N}]+/u', $text);
    }
}
