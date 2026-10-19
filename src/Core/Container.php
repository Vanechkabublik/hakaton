<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use RuntimeException;

/**
 * Минималистичный DI-контейнер с автовайрингом через Reflection.
 * Все сервисы — синглтоны в рамках процесса (в FPM это один запрос).
 */
final class Container
{
    /** @var array<string,object> */
    private array $instances = [];

    /** @var array<string,Closure> */
    private array $factories = [];

    /** @var array<string,string> */
    private array $bindings = [];

    /** @var array<string,true> */
    private array $resolving = [];

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /** Привязать интерфейс/абстракцию к конкретной реализации. */
    public function bind(string $abstract, string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        if (isset($this->bindings[$id])) {
            return $this->instances[$id] = $this->get($this->bindings[$id]);
        }

        return $this->instances[$id] = $this->make($id);
    }

    /**
     * Создать новый экземпляр с автовайрингом зависимостей.
     *
     * @param array<string,mixed> $parameters именованные скалярные параметры конструктора
     */
    public function make(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Класс {$class} не найден");
        }
        if (isset($this->resolving[$class])) {
            throw new RuntimeException("Циклическая зависимость: {$class}");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Класс {$class} нельзя создать (абстрактный или интерфейс). Добавьте bind().");
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $this->resolving[$class] = true;
        try {
            return $reflection->newInstanceArgs($this->resolveParameters($constructor, $parameters));
        } finally {
            unset($this->resolving[$class]);
        }
    }

    /**
     * Вызвать callable, подставив зависимости и именованные аргументы.
     *
     * @param array<string,mixed> $named
     */
    public function call(callable|array $callable, array $named = []): mixed
    {
        if (is_array($callable)) {
            [$target, $method] = $callable;
            $object = is_object($target) ? $target : $this->get($target);
            $reflection = new \ReflectionMethod($object, $method);

            return $reflection->invokeArgs($object, $this->resolveParameters($reflection, $named));
        }
        $reflection = new \ReflectionFunction(Closure::fromCallable($callable));

        return $reflection->invokeArgs($this->resolveParameters($reflection, $named));
    }

    /**
     * @param array<string,mixed> $named
     * @return list<mixed>
     */
    private function resolveParameters(ReflectionFunctionAbstract $function, array $named): array
    {
        $args = [];
        foreach ($function->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $named)) {
                $args[] = $this->coerce($named[$name], $type);
                continue;
            }
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->has($typeName) || interface_exists($typeName)) {
                    try {
                        $args[] = $this->get($typeName);
                        continue;
                    } catch (RuntimeException $e) {
                        if (!$parameter->isOptional() && !$type->allowsNull()) {
                            throw $e;
                        }
                    }
                }
            }
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }
            if ($type !== null && $type->allowsNull()) {
                $args[] = null;
                continue;
            }
            throw new RuntimeException(sprintf(
                'Не удаётся разрешить параметр $%s в %s',
                $name,
                $function->getName()
            ));
        }

        return $args;
    }

    private function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin() || $value === null) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            default => $value,
        };
    }
}
