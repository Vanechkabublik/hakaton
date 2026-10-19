<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Article\Article;
use App\Article\ArticleRepository;
use App\Core\Config;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Page\PageRepository;
use App\Settings\SettingsService;
use App\Taxonomy\CategoryRepository;
use App\Taxonomy\TagRepository;
use XMLWriter;

/** RSS, sitemap.xml, robots.txt и web app manifest. */
final class SeoController extends Controller
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly PageRepository $pages,
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    public function rss(): Response
    {
        return $this->renderRss($this->articles->latestForFeed(30), $this->settings->siteName(), '/rss.xml');
    }

    public function rssCategory(string $slug): Response
    {
        $category = $this->categories->findBySlug($slug) ?? $this->notFound();

        return $this->renderRss(
            $this->articles->latestForFeed(30, $category->slug),
            $this->settings->siteName() . ' — ' . $category->name,
            '/rss/' . $category->slug . '.xml',
        );
    }

    /** @param list<Article> $items */
    private function renderRss(array $items, string $title, string $selfPath): Response
    {
        $base = $this->config->appUrl();
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:media', 'http://search.yahoo.com/mrss/');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $xml->startElement('channel');
        $xml->writeElement('title', $title);
        $xml->writeElement('link', $base . '/');
        $xml->writeElement('description', $this->settings->get('site.description'));
        $xml->writeElement('language', 'ru');
        $xml->writeElement('lastBuildDate', gmdate(DATE_RSS));
        $xml->startElement('atom:link');
        $xml->writeAttribute('href', $base . $selfPath);
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('type', 'application/rss+xml');
        $xml->endElement();
        foreach ($items as $article) {
            $url = $base . $article->url();
            $xml->startElement('item');
            $xml->writeElement('title', $article->title);
            $xml->writeElement('link', $url);
            $xml->startElement('guid');
            $xml->writeAttribute('isPermaLink', 'true');
            $xml->text($url);
            $xml->endElement();
            $xml->writeElement('pubDate', ($article->publishedAt ?? $article->createdAt)->format(DATE_RSS));
            if ($article->authorName !== null) {
                $xml->writeElement('dc:creator', $article->authorName);
            }
            if ($article->categoryName !== null) {
                $xml->writeElement('category', $article->categoryName);
            }
            // Платные материалы отдаём только анонсом
            $xml->writeElement('description', $article->excerpt(300) . ($article->isPaid() ? ' [по подписке]' : ''));
            if ($article->hasCover()) {
                $xml->startElement('media:content');
                $xml->writeAttribute('url', $base . $article->coverUrl(960));
                $xml->writeAttribute('medium', 'image');
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return Response::xml($xml->outputMemory(), 'application/rss+xml')->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function sitemap(): Response
    {
        $base = $this->config->appUrl();
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $add = static function (string $loc, ?string $lastmod = null, string $freq = 'weekly', string $priority = '0.5') use ($xml): void {
            $xml->startElement('url');
            $xml->writeElement('loc', $loc);
            if ($lastmod !== null) {
                $xml->writeElement('lastmod', (new \DateTimeImmutable($lastmod))->format('Y-m-d\TH:i:sP'));
            }
            $xml->writeElement('changefreq', $freq);
            $xml->writeElement('priority', $priority);
            $xml->endElement();
        };

        $add($base . '/', null, 'hourly', '1.0');
        foreach ($this->categories->all() as $category) {
            $add($base . '/category/' . $category->slug, null, 'daily', '0.8');
        }
        foreach ($this->tags->popular(100) as $tag) {
            $add($base . '/tag/' . $tag->slug, null, 'weekly', '0.4');
        }
        foreach ($this->pages->all() as $page) {
            if ($page->isPublished) {
                $add($base . '/' . $page->slug, $page->updatedAt->format(DATE_ATOM), 'monthly', '0.3');
            }
        }
        foreach ($this->articles->sitemapEntries() as $row) {
            $add($base . '/news/' . $row['slug'], (string) $row['updated'], 'weekly', '0.7');
        }
        $xml->endElement();
        $xml->endDocument();

        return Response::xml($xml->outputMemory())->withHeader('Cache-Control', 'public, max-age=900');
    }

    public function robots(): Response
    {
        $base = $this->config->appUrl();
        $disallow = ['/admin', '/account', '/api/', '/login', '/register', '/forgot-password', '/reset-password/', '/verify-email/',
            '/subscribe/checkout', '/payment/', '/gift', '/search', '/preview/', '/unsubscribe/', '/logout', '/auth/', '/webhooks/'];
        $rules = implode("\n", array_map(static fn (string $p): string => 'Disallow: ' . $p, $disallow));
        $text = "User-agent: *\n{$rules}\nAllow: /\n\n"
            . "User-agent: Yandex\n{$rules}\nAllow: /\nClean-param: utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&fbclid\n\n"
            . "Sitemap: {$base}/sitemap.xml\n";

        return Response::text($text)->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function manifest(): Response
    {
        $name = $this->settings->siteName();

        return Response::json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#2563eb',
            'lang' => 'ru',
            'icons' => [
                ['src' => '/assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ])->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
