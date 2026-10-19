<?php

declare(strict_types=1);

namespace App\Comment;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class Comment extends Entity
{
    public const EDIT_WINDOW_SECONDS = 300;

    public int $id = 0;
    public int $articleId = 0;
    public ?int $userId = null;
    public ?int $parentId = null;
    public string $body = '';
    public string $status = 'approved';
    public int $likesCount = 0;
    public int $reportsCount = 0;
    public ?string $ip = null;
    public ?DateTimeImmutable $editedAt = null;
    public ?int $moderatedBy = null;
    public ?DateTimeImmutable $moderatedAt = null;
    public DateTimeImmutable $createdAt;

    // JOIN
    public ?string $authorName = null;
    public ?string $authorAvatar = null;
    public ?string $authorRole = null;
    public ?string $authorSlug = null;
    public ?string $articleTitle = null;
    public ?string $articleSlug = null;
    public bool $likedByMe = false;

    /** @var list<Comment> */
    public array $children = [];
    public int $depth = 0;

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function canBeEditedBy(?int $userId, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $userId !== null
            && $this->userId === $userId
            && ($now->getTimestamp() - $this->createdAt->getTimestamp()) <= self::EDIT_WINDOW_SECONDS
            && in_array($this->status, ['approved', 'pending'], true);
    }

    public function editableUntil(): DateTimeImmutable
    {
        return $this->createdAt->modify('+' . self::EDIT_WINDOW_SECONDS . ' seconds');
    }

    public function authorDisplayName(): string
    {
        return $this->authorName ?? 'Удалённый пользователь';
    }
}
