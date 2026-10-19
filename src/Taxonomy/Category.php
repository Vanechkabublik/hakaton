<?php

declare(strict_types=1);

namespace App\Taxonomy;

use App\Core\Database\Entity;

final class Category extends Entity
{
    public int $id = 0;
    public string $slug = '';
    public string $name = '';
    public string $description = '';
    public string $color = '#2563eb';
    public int $sortOrder = 0;
    /** Количество опубликованных материалов (только в выборках со счётчиком). */
    public int $articlesCount = 0;
}
