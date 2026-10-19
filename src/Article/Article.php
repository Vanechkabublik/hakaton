<?php

declare(strict_types=1);

namespace App\Article;

use App\Core\Database\Entity;
use App\Support\HtmlText;
use DateTimeImmutable;

final class Article extends Entity
{
    public int $id = 0;
    public ArticleType $type = ArticleType::News;
    public ArticleStatus $status = ArticleStatus::Draft;
    public AccessLevel $access = AccessLevel::Free;
    public string $slug = '';
    public string $title = '';
    public string $subtitle = '';
    public string $lead = '';
    public string $body = '';
    public ?int $coverMediaId = null;
    public string $coverCaption = '';
    public ?int $authorId = null;
    public ?int $categoryId = null;
    public int $readingMinutes = 1;
    public string $seoTitle = '';
    public string $seoDescription = '';
    public bool $isFeatured = false;
    public bool $allowComments = true;
    public bool $isLive = false;
    public ?DateTimeImmutable $publishAt = null;
    public ?DateTimeImmutable $publishedAt = null;
    public ?DateTimeImmutable $archivedAt = null;
    public ?DateTimeImmutable $submittedAt = null;
    public ?int $moderatedBy = null;
    public string $moderationNote = '';
    public int $viewsCount = 0;
    public int $commentsCount = 0;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    // Поля из JOIN-ов (заполняются выборками репозитория)
    public ?string $authorName = null;
    public ?string $authorSlug = null;
    public ?string $authorAvatar = null;
    public ?string $categoryName = null;
    public ?string $categorySlug = null;
    public ?string $categoryColor = null;
    /** @var array<string,string> */
    public array $coverVariants = [];
    public ?string $coverAlt = null;
    public ?int $coverWidth = null;
    public ?int $coverHeight = null;
    public float $rank = 0.0;
    public ?string $headline = null;

    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::Published;
    }

    public function isPaid(): bool
    {
        return $this->access->isPaid();
    }

    public function hasCover(): bool
    {
        return $this->coverVariants !== [];
    }

    public function coverUrl(int $width = 960): ?string
    {
        if ($this->coverVariants === []) {
            return null;
        }
        $widths = array_map('intval', array_keys($this->coverVariants));
        sort($widths);
        $chosen = end($widths);
        foreach ($widths as $w) {
            if ($w >= $width) {
                $chosen = $w;
                break;
            }
        }

        return '/uploads/' . $this->coverVariants[(string) $chosen];
    }

    public function coverSrcset(): string
    {
        $parts = [];
        foreach ($this->coverVariants as $width => $path) {
            $parts[] = '/uploads/' . $path . ' ' . (int) $width . 'w';
        }

        return implode(', ', $parts);
    }

    public function url(): string
    {
        return '/news/' . $this->slug;
    }

    /** Описание для карточек и meta: lead или начало текста. */
    public function excerpt(int $length = 180): string
    {
        $text = trim($this->lead) !== '' ? $this->lead : strip_tags($this->body);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return HtmlText::trimTrailingPunctuation(mb_substr($text, 0, $length - 1)) . '…';
    }

    /** Отображаемая дата публикации (запланированная/фактическая/создания). */
    public function displayDate(): DateTimeImmutable
    {
        return $this->publishedAt ?? $this->publishAt ?? $this->createdAt;
    }
}
