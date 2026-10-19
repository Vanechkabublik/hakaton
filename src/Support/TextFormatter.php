<?php

declare(strict_types=1);

namespace App\Support;

/** Безопасное форматирование пользовательского текста (комментарии): экранирование, ссылки, переносы строк. */
final class TextFormatter
{
    public static function commentHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = preg_replace_callback(
            '#\bhttps?://[^\s<]+#iu',
            static function (array $m): string {
                $url = $m[0];
                $trail = '';
                while ($url !== '' && str_contains('.,;:!?)', substr($url, -1))) {
                    $trail = substr($url, -1) . $trail;
                    $url = substr($url, 0, -1);
                }
                $display = mb_strlen($url) > 60 ? mb_substr($url, 0, 57) . '…' : $url;

                return '<a href="' . $url . '" rel="nofollow ugc noopener noreferrer" target="_blank">' . $display . '</a>' . $trail;
            },
            $escaped
        ) ?? $escaped;

        return nl2br($linked, false);
    }

    public static function containsLink(string $text): bool
    {
        return preg_match('#(https?://|www\.|t\.me/|[a-z0-9-]+\.(ru|com|net|org|io|рф|xyz|top|info)\b)#iu', $text) === 1;
    }

    public static function linkCount(string $text): int
    {
        return (int) preg_match_all('#https?://|www\.#iu', $text);
    }
}
