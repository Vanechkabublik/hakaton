<?php

declare(strict_types=1);

namespace App\Core\Database;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Базовый класс доменных сущностей. Гидратация из строки БД идёт по именам и типам свойств:
 * snake_case колонка → camelCase свойство; поддерживаются int/float/bool/string, даты, JSON-массивы и enum.
 */
abstract class Entity
{
    /** @var array<class-string,array<string,array{prop:ReflectionProperty,type:string,nullable:bool}>> */
    private static array $schema = [];

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): static
    {
        $schema = self::schemaFor(static::class);
        $object = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        foreach ($row as $column => $value) {
            $property = self::camel((string) $column);
            if (!isset($schema[$property])) {
                continue;
            }
            ['prop' => $reflection, 'type' => $type, 'nullable' => $nullable] = $schema[$property];
            $reflection->setValue($object, self::cast($value, $type, $nullable));
        }

        return $object;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<static>
     */
    public static function fromRows(array $rows): array
    {
        return array_map(static fn (array $row): static => static::fromRow($row), $rows);
    }

    public static function camel(string $snake): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snake))));
    }

    /** @return array<string,array{prop:ReflectionProperty,type:string,nullable:bool}> */
    private static function schemaFor(string $class): array
    {
        if (isset(self::$schema[$class])) {
            return self::$schema[$class];
        }
        $schema = [];
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $type = $property->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }
            $schema[$property->getName()] = [
                'prop' => $property,
                'type' => $type->getName(),
                'nullable' => $type->allowsNull(),
            ];
        }

        return self::$schema[$class] = $schema;
    }

    private static function cast(mixed $value, string $type, bool $nullable): mixed
    {
        if ($value === null) {
            return $nullable ? null : match ($type) {
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                'string' => '',
                'array' => [],
                default => null,
            };
        }

        return match (true) {
            $type === 'int' => (int) $value,
            $type === 'float' => (float) $value,
            $type === 'bool' => $value === true || $value === 't' || $value === '1' || $value === 1,
            $type === 'string' => (string) $value,
            $type === 'array' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            is_a($type, DateTimeImmutable::class, true) => new DateTimeImmutable((string) $value, new DateTimeZone('UTC')),
            enum_exists($type) && is_a($type, BackedEnum::class, true) => $type::tryFrom($value) ?? $type::cases()[0],
            default => $value,
        };
    }
}
