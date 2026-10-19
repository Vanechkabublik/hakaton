<?php

declare(strict_types=1);

namespace App\Core\View;

/** Контекст одного вызова render(): выбранный макет и накопленные секции. */
final class ViewFrame
{
    public ?string $layout = null;

    /** @var array<string,mixed> */
    public array $layoutData = [];

    /** @var array<string,string> */
    public array $sections = [];
}
