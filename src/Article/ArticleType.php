<?php

declare(strict_types=1);

namespace App\Article;

enum ArticleType: string
{
    case News = 'news';
    case Analytics = 'analytics';
    case Longread = 'longread';
    case Opinion = 'opinion';
    case Photo = 'photo';
    case Liveblog = 'liveblog';

    public function label(): string
    {
        return match ($this) {
            self::News => 'Новость',
            self::Analytics => 'Аналитика',
            self::Longread => 'Лонгрид',
            self::Opinion => 'Мнение',
            self::Photo => 'Фоторепортаж',
            self::Liveblog => 'Live-блог',
        };
    }

    /** Значение schema.org для JSON-LD. */
    public function schemaType(): string
    {
        return match ($this) {
            self::News, self::Liveblog => 'NewsArticle',
            self::Opinion => 'OpinionNewsArticle',
            self::Analytics => 'AnalysisNewsArticle',
            default => 'Article',
        };
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
