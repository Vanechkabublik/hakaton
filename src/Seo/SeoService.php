<?php

declare(strict_types=1);

namespace App\Seo;

use App\Article\AccessLevel;
use App\Article\Article;
use App\Core\Config;
use App\Settings\SettingsService;
use App\Taxonomy\Category;
use App\Taxonomy\Tag;
use App\User\User;

/** Формирование SEO-метаданных и структурированных данных schema.org (Article, BreadcrumbList, Organization). */
final class SeoService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    public function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return $this->config->appUrl() . '/' . ltrim($path, '/');
    }

    public function home(): PageMeta
    {
        $site = $this->settings->siteName();
        $title = $this->template($this->settings->get('seo.home_title'), ['site' => $site, 'tagline' => $this->settings->get('site.tagline')]);
        $meta = new PageMeta($title, $this->settings->get('site.description'), $this->absolute('/'));
        $meta->image = $this->defaultImage();
        $meta->addJsonLd($this->organization());
        $meta->addJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site,
            'url' => $this->absolute('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => $this->absolute('/search') . '?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ]);

        return $meta;
    }

    public function page(string $title, string $description, string $path, bool $index = true): PageMeta
    {
        $meta = new PageMeta($this->pageTitle($title), $description, $this->absolute($path));
        $meta->image = $this->defaultImage();
        if (!$index) {
            $meta->noindex();
        }

        return $meta;
    }

    public function category(Category $category, int $page = 1): PageMeta
    {
        $path = '/category/' . $category->slug;
        $meta = $this->page($category->name, $category->description !== '' ? $category->description : "Материалы рубрики «{$category->name}»", $path . ($page > 1 ? '?page=' . $page : ''));
        $meta->addJsonLd($this->breadcrumbs([['Главная', '/'], [$category->name, $path]]));

        return $meta;
    }

    public function tag(Tag $tag): PageMeta
    {
        return $this->page('#' . $tag->name, "Материалы по теме «{$tag->name}»", '/tag/' . $tag->slug);
    }

    public function author(User $author): PageMeta
    {
        $meta = $this->page($author->name, $author->bio !== '' ? $author->bio : "Материалы автора {$author->name}", '/author/' . $author->slug);
        $meta->ogType = 'profile';
        $meta->addJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $author->name,
            'url' => $this->absolute('/author/' . $author->slug),
        ]);

        return $meta;
    }

    /**
     * @param list<array{name:string}> $tags
     */
    public function article(Article $article, array $tags, bool $locked): PageMeta
    {
        $site = $this->settings->siteName();
        $title = $article->seoTitle !== '' ? $article->seoTitle : $article->title;
        $fullTitle = $this->template($this->settings->get('seo.article_title_template'), [
            'title' => $title, 'site' => $site, 'category' => (string) $article->categoryName,
        ]);
        $description = $article->seoDescription !== '' ? $article->seoDescription : $this->template($this->settings->get('seo.description_template'), [
            'lead' => $article->excerpt(200), 'title' => $article->title, 'site' => $site,
        ]);
        $url = $this->absolute($article->url());
        $image = $article->hasCover() ? $this->absolute((string) $article->coverUrl(1280)) : $this->defaultImage();

        $meta = new PageMeta($fullTitle, $description, $url, 'index,follow', 'article', $image);
        $meta->publishedTime = $article->publishedAt?->format(DATE_ATOM);
        $meta->modifiedTime = $article->updatedAt->format(DATE_ATOM);
        $meta->author = $article->authorName;
        $meta->section = $article->categoryName;
        $meta->keywords = array_map(static fn (array $t): string => $t['name'], $tags);

        $ld = [
            '@context' => 'https://schema.org',
            '@type' => $article->type->schemaType(),
            'headline' => mb_substr($article->title, 0, 110),
            'description' => $description,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'url' => $url,
            'datePublished' => $article->publishedAt?->format(DATE_ATOM),
            'dateModified' => $article->updatedAt->format(DATE_ATOM),
            'image' => $image !== null ? [$image] : [],
            'author' => $article->authorName !== null
                ? [['@type' => 'Person', 'name' => $article->authorName, 'url' => $article->authorSlug ? $this->absolute('/author/' . $article->authorSlug) : null]]
                : [['@type' => 'Organization', 'name' => $site]],
            'publisher' => ['@type' => 'Organization', 'name' => $site, 'url' => $this->absolute('/'), 'logo' => $this->logoObject()],
            'articleSection' => $article->categoryName,
            'keywords' => implode(', ', $meta->keywords) ?: null,
            'inLanguage' => 'ru',
        ];
        if ($article->access !== AccessLevel::Free) {
            // Разметка платного контента: поисковики не считают закрытый текст клоакингом
            $ld['isAccessibleForFree'] = false;
            $ld['hasPart'] = ['@type' => 'WebPageElement', 'isAccessibleForFree' => false, 'cssSelector' => '.article-body'];
        }
        $meta->addJsonLd(self::clean($ld));

        $crumbs = [['Главная', '/']];
        if ($article->categorySlug !== null) {
            $crumbs[] = [(string) $article->categoryName, '/category/' . $article->categorySlug];
        }
        $crumbs[] = [$article->title, $article->url()];
        $meta->addJsonLd($this->breadcrumbs($crumbs));
        $meta->addJsonLd($this->organization());

        return $meta;
    }

    /** @param list<array{0:string,1:string}> $items */
    public function breadcrumbs(array $items): array
    {
        $list = [];
        foreach ($items as $i => [$name, $path]) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $this->absolute($path)];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    /** @return array<string,mixed> */
    public function organization(): array
    {
        return self::clean([
            '@context' => 'https://schema.org',
            '@type' => 'NewsMediaOrganization',
            'name' => $this->settings->siteName(),
            'url' => $this->absolute('/'),
            'logo' => $this->logoObject(),
            'email' => $this->settings->get('site.contact_email') ?: null,
            'sameAs' => [],
        ]);
    }

    private function logoObject(): ?array
    {
        $logo = $this->settings->get('site.logo');

        return $logo !== '' ? ['@type' => 'ImageObject', 'url' => $this->absolute('/uploads/' . $logo)] : null;
    }

    private function defaultImage(): ?string
    {
        $og = $this->settings->get('seo.og_image') ?: $this->settings->get('site.logo');

        return $og !== '' ? $this->absolute('/uploads/' . $og) : null;
    }

    private function pageTitle(string $title): string
    {
        return $this->template($this->settings->get('seo.title_template'), ['title' => $title, 'site' => $this->settings->siteName()]);
    }

    /** @param array<string,string> $vars */
    private function template(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = $value;
        }

        return trim(strtr($template, $replace));
    }

    /** Убирает null и пустые значения из JSON-LD. */
    private static function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::clean($value);
            }
            if ($data[$key] === null || $data[$key] === [] || $data[$key] === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
