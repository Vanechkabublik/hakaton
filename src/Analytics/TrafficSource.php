<?php

declare(strict_types=1);

namespace App\Analytics;

/** Классификация источника перехода по Referer и UTM-меткам. */
final class TrafficSource
{
    private const SEARCH = '/(^|\.)(yandex\.[a-z.]+|ya\.ru|google\.[a-z.]+|bing\.com|duckduckgo\.com|go\.mail\.ru|rambler\.ru|yahoo\.com|ecosia\.org|startpage\.com)$/i';
    private const SOCIAL = '/(^|\.)(vk\.com|vk\.ru|t\.me|telegram\.org|facebook\.com|instagram\.com|twitter\.com|x\.com|ok\.ru|youtube\.com|dzen\.ru|zen\.yandex\.ru|linkedin\.com|reddit\.com|pinterest\.[a-z]+)$/i';

    /**
     * @return array{source:string,host:?string,utm:?string}
     */
    public static function classify(?string $referer, string $ownHost, ?string $utmSource, ?string $utmMedium): array
    {
        $utm = $utmSource !== null && $utmSource !== '' ? mb_substr(strtolower($utmSource), 0, 80) : null;
        $host = $referer !== null && $referer !== '' ? strtolower((string) parse_url($referer, PHP_URL_HOST)) : null;
        $host = $host !== null && $host !== '' ? mb_substr($host, 0, 120) : null;

        if (in_array(strtolower((string) $utmMedium), ['email', 'newsletter', 'digest'], true) || in_array($utm, ['email', 'newsletter', 'digest'], true)) {
            return ['source' => 'email', 'host' => $host, 'utm' => $utm];
        }
        if ($host === null) {
            return ['source' => $utm !== null ? 'other' : 'direct', 'host' => null, 'utm' => $utm];
        }
        $own = explode(':', $ownHost)[0];
        if ($host === $own || str_ends_with($host, '.' . $own)) {
            return ['source' => 'internal', 'host' => $host, 'utm' => $utm];
        }
        if (preg_match(self::SEARCH, $host)) {
            return ['source' => 'search', 'host' => $host, 'utm' => $utm];
        }
        if (preg_match(self::SOCIAL, $host)) {
            return ['source' => 'social', 'host' => $host, 'utm' => $utm];
        }

        return ['source' => 'other', 'host' => $host, 'utm' => $utm];
    }
}
