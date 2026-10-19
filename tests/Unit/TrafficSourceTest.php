<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Analytics\TrafficSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrafficSourceTest extends TestCase
{
    #[DataProvider('cases')]
    public function testClassify(?string $referer, ?string $utmSource, ?string $utmMedium, string $expected): void
    {
        $this->assertSame($expected, TrafficSource::classify($referer, 'news.vancodex.tech', $utmSource, $utmMedium)['source']);
    }

    /** @return array<string,array{?string,?string,?string,string}> */
    public static function cases(): array
    {
        return [
            'прямой заход' => [null, null, null, 'direct'],
            'пустой referer' => ['', null, null, 'direct'],
            'Яндекс' => ['https://yandex.ru/search/?text=x', null, null, 'search'],
            'Google' => ['https://www.google.com/', null, null, 'search'],
            'ВКонтакте' => ['https://vk.com/feed', null, null, 'social'],
            'Telegram' => ['https://t.me/channel', null, null, 'social'],
            'свой сайт' => ['https://news.vancodex.tech/category/tech', null, null, 'internal'],
            'поддомен своего сайта' => ['https://m.news.vancodex.tech/', null, null, 'internal'],
            'чужой сайт' => ['https://example.org/post', null, null, 'other'],
            'utm_medium=email' => [null, 'digest', 'email', 'email'],
            'utm_source=newsletter с чужим referer' => ['https://mail.example.org/', 'newsletter', null, 'email'],
            'utm без referer' => [null, 'partner', 'cpc', 'other'],
            'похожий домен не считается поисковиком' => ['https://notyandex.ru/', null, null, 'other'],
            'похожий домен не считается своим' => ['https://evil-news.vancodex.tech.attacker.test/', null, null, 'other'],
        ];
    }

    public function testReturnsHostAndLowercasedUtm(): void
    {
        $result = TrafficSource::classify('https://YANDEX.ru/x', 'news.vancodex.tech', 'Partner', null);
        $this->assertSame('yandex.ru', $result['host']);
        $this->assertSame('partner', $result['utm']);
    }
}
