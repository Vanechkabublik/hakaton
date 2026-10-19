<?php

declare(strict_types=1);

namespace App\Page;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class Page extends Entity
{
    public int $id = 0;
    public string $slug = '';
    public string $title = '';
    public string $body = '';
    public string $seoDescription = '';
    public bool $isPublished = true;
    public DateTimeImmutable $updatedAt;
}
