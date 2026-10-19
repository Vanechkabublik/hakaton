<?php

declare(strict_types=1);

namespace App\Taxonomy;

use App\Core\Database\Entity;

final class Tag extends Entity
{
    public int $id = 0;
    public string $slug = '';
    public string $name = '';
    public int $articlesCount = 0;
}
