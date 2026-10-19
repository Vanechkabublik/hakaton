<?php

declare(strict_types=1);

namespace App\Paywall;

/** Грубое определение поисковых и прочих роботов: им отдаём превью и не считаем просмотры. */
final class BotDetector
{
    private const PATTERN = '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|bingpreview|yandex(bot|images|metrika|direct|market|news|video|webmaster|turbo)|headless|lighthouse|wget|curl|python-requests|go-http-client|monitor|uptime|preview|telegram|whatsapp|vkshare|embedly/i';

    public function isBot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match(self::PATTERN, $userAgent) === 1;
    }
}
