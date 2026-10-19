<?php

declare(strict_types=1);

namespace App\Media;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class Media extends Entity
{
    public int $id = 0;
    public ?int $userId = null;
    public string $originalName = '';
    public string $mime = 'image/webp';
    public int $width = 0;
    public int $height = 0;
    public int $sizeBytes = 0;
    public string $alt = '';
    /** @var array<string,string> ширина => путь относительно storage/uploads */
    public array $variants = [];
    public DateTimeImmutable $createdAt;

    /** URL варианта, ближайшего к запрошенной ширине (не меньше, если есть). */
    public function url(int $width = 960): string
    {
        $path = $this->pathFor($width);

        return $path === null ? '' : '/uploads/' . $path;
    }

    public function pathFor(int $width): ?string
    {
        if ($this->variants === []) {
            return null;
        }
        $widths = array_map('intval', array_keys($this->variants));
        sort($widths);
        foreach ($widths as $w) {
            if ($w >= $width) {
                return $this->variants[(string) $w];
            }
        }

        return $this->variants[(string) end($widths)];
    }

    public function srcset(): string
    {
        $parts = [];
        foreach ($this->variants as $width => $path) {
            $parts[] = '/uploads/' . $path . ' ' . (int) $width . 'w';
        }

        return implode(', ', $parts);
    }

    /** @return list<string> все файлы медиа (для удаления). */
    public function allPaths(): array
    {
        return array_values($this->variants);
    }
}
