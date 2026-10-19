<?php

declare(strict_types=1);

namespace App\Settings;

/** Описание одной настройки: используется и для значений по умолчанию, и для генерации формы в админке. */
final class SettingDefinition
{
    /**
     * @param 'text'|'textarea'|'int'|'bool'|'select'|'secret'|'image' $type
     * @param array<string,string> $options значения для select
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly string $default = '',
        public readonly ?string $env = null,
        public readonly string $help = '',
        public readonly array $options = [],
    ) {
    }

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }
}
