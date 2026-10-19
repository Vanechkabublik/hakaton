<?php

declare(strict_types=1);

namespace App\Article;

/** Параметры выборки ленты опубликованных материалов. */
final class FeedCriteria
{
    /**
     * @param list<string> $tagSlugs
     */
    public function __construct(
        public readonly ?string $categorySlug = null,
        public readonly array $tagSlugs = [],
        public readonly ?ArticleType $type = null,
        public readonly ?int $authorId = null,
        /** @var list<int> */
        public readonly array $excludeIds = [],
        public readonly bool $onlyFree = false,
    ) {
    }

    /** @return array<string,mixed> параметры для query-string ссылок */
    public function toQuery(): array
    {
        $query = [];
        if ($this->categorySlug !== null) {
            $query['category'] = $this->categorySlug;
        }
        if ($this->tagSlugs !== []) {
            $query['tags'] = implode(',', $this->tagSlugs);
        }
        if ($this->type !== null) {
            $query['type'] = $this->type->value;
        }
        if ($this->authorId !== null) {
            $query['author'] = $this->authorId;
        }

        return $query;
    }
}
