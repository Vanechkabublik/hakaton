<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Settings\SettingsService;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationTestCase;

final class PublicSiteTest extends IntegrationTestCase
{
    private function category(string $name, string $slug): int
    {
        return (int) $this->db->insert('categories', ['name' => $name, 'slug' => $slug, 'color' => '#2563eb']);
    }

    public function testHomePageListsPublishedArticlesOnly(): void
    {
        $this->fx->article(['title' => 'ОПУБЛИКОВАННАЯ', 'slug' => 'pub']);
        $this->fx->article(['title' => 'ЧЕРНОВИК', 'slug' => 'draft', 'status' => 'draft', 'published_at' => null]);
        $this->fx->article(['title' => 'В-АРХИВЕ', 'slug' => 'old', 'status' => 'archived']);

        $response = $this->get('/');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('ОПУБЛИКОВАННАЯ', $response->body());
        $this->assertStringNotContainsString('ЧЕРНОВИК', $response->body());
        $this->assertStringNotContainsString('В-АРХИВЕ', $response->body());
    }

    public function testFeedApiPaginatesWithCursorWithoutDuplicates(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->fx->article(['title' => sprintf('Материал-%02d', $i), 'slug' => "m-{$i}", 'published_at' => new \DateTimeImmutable("-{$i} minutes")]);
        }

        $first = json_decode($this->request('GET', '/api/feed', [], ['Accept' => 'application/json'])->body(), true);
        $this->assertTrue($first['ok']);
        $this->assertNotNull($first['next'], 'Есть следующая страница');
        $second = json_decode($this->get('/api/feed', ['cursor' => $first['next']])->body(), true);

        $this->assertGreaterThan(0, $first['count']);
        $this->assertGreaterThan(0, $second['count']);
        preg_match_all('/Материал-\d{2}/', $first['html'], $a);
        preg_match_all('/Материал-\d{2}/', $second['html'], $b);
        $this->assertSame([], array_intersect(array_unique($a[0]), array_unique($b[0])), 'Страницы не пересекаются');
        $this->assertSame('Материал-01', $a[0][0], 'Сначала самые свежие');
    }

    public function testFeedFiltersByCategoryAndRejectsGarbageCursor(): void
    {
        $tech = $this->category('Технологии', 'tech');
        $this->fx->article(['title' => 'ТЕХ-СТАТЬЯ', 'slug' => 'a', 'category_id' => $tech]);
        $this->fx->article(['title' => 'ПРОЧАЯ-СТАТЬЯ', 'slug' => 'b']);

        $json = json_decode($this->get('/api/feed', ['category' => 'tech', 'cursor' => "'; DROP TABLE articles; --"])->body(), true);

        $this->assertStringContainsString('ТЕХ-СТАТЬЯ', $json['html']);
        $this->assertStringNotContainsString('ПРОЧАЯ-СТАТЬЯ', $json['html']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM pg_tables WHERE tablename = 'articles'"));
    }

    public function testCategoryPageAnd404ForUnknown(): void
    {
        $this->category('Технологии', 'tech');

        $this->assertSame(200, $this->get('/category/tech')->status());
        $this->assertSame(404, $this->get('/category/nope')->status());
        $this->assertSame(404, $this->get('/news/nope')->status());
        $this->assertSame(404, $this->get('/totally/unknown')->status());
    }

    public function testSearchFindsByStemmedWordsAndEscapesQuery(): void
    {
        $this->fx->article(['title' => 'Облачные технологии меняют бизнес', 'slug' => 'cloud']);
        $this->fx->article(['title' => 'Рецепт борща', 'slug' => 'borsch']);

        $found = $this->get('/search', ['q' => 'облачная технология']);
        $this->assertSame(200, $found->status());
        $this->assertStringContainsString('Облачные технологии', $found->body());
        $this->assertStringNotContainsString('Рецепт борща', $found->body());

        $evil = $this->get('/search', ['q' => '<script>alert(1)</script>']);
        $this->assertSame(200, $evil->status());
        $this->assertStringNotContainsString('<script>alert(1)</script>', $evil->body());
    }

    public function testSuggestReturnsMatchingArticlesAndTopics(): void
    {
        $this->category('Технологии', 'tech');
        $this->fx->article(['title' => 'Технологии будущего', 'slug' => 'future']);

        $json = json_decode($this->get('/api/search/suggest', ['q' => 'технол'])->body(), true);

        $this->assertTrue($json['ok']);
        $this->assertNotEmpty($json['articles']);
        $this->assertNotEmpty($json['topics']);
    }

    public function testArticlePageHasSeoMarkup(): void
    {
        $article = $this->fx->article(['title' => 'Заголовок для SEO', 'slug' => 'seo-page', 'lead' => 'Описание материала']);

        $html = $this->get('/news/' . $article->slug)->body();

        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/news/seo-page">', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('"@type":"NewsArticle"', str_replace(' ', '', $html));
        $this->assertStringContainsString('<title>Заголовок для SEO', $html);
    }

    public function testSecurityHeadersAreSentWithNonceCsp(): void
    {
        $response = $this->get('/');

        $csp = (string) $response->header('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $csp);
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertNotNull($response->header('Referrer-Policy'));
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertStringContainsString('nonce="' . $m[1] . '"', $response->body(), 'Скрипты страницы подписаны тем же nonce');
    }

    public function testNonceIsDifferentOnEveryResponse(): void
    {
        preg_match("/'nonce-([^']+)'/", (string) $this->get('/')->header('Content-Security-Policy'), $a);
        preg_match("/'nonce-([^']+)'/", (string) $this->get('/')->header('Content-Security-Policy'), $b);

        $this->assertNotSame($a[1], $b[1]);
    }

    public function testAnonymousPageViewDoesNotCreateSession(): void
    {
        $response = $this->get('/');

        $this->assertArrayNotHasKey('nsid', $this->cookies, 'Анонимный просмотр не должен плодить сессии (и cookie)');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM sessions'));
        $this->assertNull($response->header('Set-Cookie'));
    }

    public function testRssContainsLatestArticles(): void
    {
        $this->fx->article(['title' => 'Новость & «кавычки»', 'slug' => 'rss-1']);

        $response = $this->get('/rss.xml');

        $this->assertStringContainsString('xml', (string) $response->header('Content-Type'));
        $xml = simplexml_load_string($response->body());
        $this->assertNotFalse($xml, 'RSS должен быть валидным XML');
        $this->assertSame('Новость & «кавычки»', (string) $xml->channel->item[0]->title);
        $this->assertStringContainsString('/news/rss-1', (string) $xml->channel->item[0]->link);
    }

    public function testSitemapListsPublishedOnly(): void
    {
        $this->fx->article(['slug' => 'in-sitemap']);
        $this->fx->article(['slug' => 'hidden-draft', 'status' => 'draft', 'published_at' => null]);

        $body = $this->get('/sitemap.xml')->body();

        $this->assertNotFalse(simplexml_load_string($body));
        $this->assertStringContainsString('/news/in-sitemap', $body);
        $this->assertStringNotContainsString('hidden-draft', $body);
    }

    public function testRobotsHidesPrivateAreasAndPointsToSitemap(): void
    {
        $body = $this->get('/robots.txt')->body();

        foreach (['/admin', '/account', '/api/', '/webhooks/'] as $path) {
            $this->assertStringContainsString("Disallow: {$path}", $body);
        }
        $this->assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $body);
    }

    public function testStaticPagesAreServedFromDatabase(): void
    {
        $this->db->insert('pages', ['slug' => 'about', 'title' => 'О проекте', 'body' => '<p>Мы делаем новости.</p>', 'seo_description' => '']);

        $response = $this->get('/about');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Мы делаем новости.', $response->body());
        $this->assertSame(404, $this->get('/terms')->status(), 'Страницы, которой нет в БД, нет и на сайте');
    }

    public function testDarkThemeAndCookieBannerMarkupIsPresent(): void
    {
        $html = $this->get('/')->body();

        $this->assertStringContainsString('id="theme-toggle"', $html);
        $this->assertStringContainsString('cookie', strtolower($html));
    }

    public function testOpenRedirectIsNotPossibleAfterLogin(): void
    {
        $this->fx->user(['email' => 'redir@example.test']);
        $this->get('/account'); // кладёт intended=/account
        $this->csrfToken('/login');

        // подмена intended на внешний адрес невозможна: используется только локальный путь
        $response = $this->post('/login', ['email' => 'redir@example.test', 'password' => Fixtures::PASSWORD, 'intended' => 'https://evil.test/'], '/login');

        $this->assertSame('/account', parse_url((string) $response->header('Location'), PHP_URL_PATH));
        $this->assertNull(parse_url((string) $response->header('Location'), PHP_URL_HOST));
    }

    public function testSettingsFromAdminChangeSiteName(): void
    {
        $this->c->get(SettingsService::class)->set('site.name', 'Тестовое издание');

        $this->assertStringContainsString('Тестовое издание', $this->get('/')->body());
    }
}
