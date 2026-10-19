<?php

declare(strict_types=1);

namespace App\Seo;

/** SEO-данные страницы: рендерятся в <head> макета (title, description, canonical, Open Graph, Twitter Cards, JSON-LD). */
final class PageMeta
{
    /** @var list<array<string,mixed>> */
    public array $jsonLd = [];

    /** @var list<string> */
    public array $keywords = [];

    public function __construct(
        public string $title = '',
        public string $description = '',
        public string $canonical = '',
        public string $robots = 'index,follow',
        public string $ogType = 'website',
        public ?string $image = null,
        public ?string $publishedTime = null,
        public ?string $modifiedTime = null,
        public ?string $author = null,
        public ?string $section = null,
        public ?string $prevUrl = null,
        public ?string $nextUrl = null,
    ) {
    }

    public function noindex(): self
    {
        $this->robots = 'noindex,follow';

        return $this;
    }

    public function noindexIf(bool $condition): self
    {
        return $condition ? $this->noindex() : $this;
    }

    /** @param array<string,mixed> $block */
    public function addJsonLd(array $block): self
    {
        $this->jsonLd[] = $block;

        return $this;
    }
}
