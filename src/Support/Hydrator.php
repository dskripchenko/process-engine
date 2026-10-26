<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Dskripchenko\ProcessEngine\Attributes\ListOf;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use UnitEnum;

/**
 * Turns plain PHP objects (contexts, events) into JSON-ready arrays and back.
 *
 * Supported: scalars, arrays, backed enums, DateTimeImmutable (ISO 8601),
 * nested objects with public properties. An object is rebuilt through its
 * constructor (parameters matched by name) and then its remaining public
 * properties are set. Lists of objects need `#[ListOf(Item::class)]`.
 */
final class Hydrator
{
    public static function dehydrate(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::RFC3339_EXTENDED);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::dehydrate($item);
            }

            return $out;
        }
        if ($value instanceof JsonSerializable) {
            return self::dehydrate($value->jsonSerialize());
        }
        if (is_object($value)) {
            $out = [];
            foreach ((new ReflectionClass($value))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic() || ! $property->isInitialized($value)) {
                    continue;
                }
                $out[$property->getName()] = self::dehydrate($property->getValue($value));
            }

            return $out;
        }

        throw new InvalidArgumentException('Cannot serialize a value of type '.get_debug_type($value));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<mixed>  $data
     * @return T
     */
    public static function hydrate(string $class, array $data): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $args = [];
        $used = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();
                if (array_key_exists($name, $data)) {
                    $args[$name] = self::cast($data[$name], $parameter, $parameter->getAttributes(ListOf::class));
                    $used[$name] = true;
                } elseif (! $parameter->isOptional()) {
                    if ($parameter->allowsNull()) {
                        $args[$name] = null;
                    } else {
                        throw new InvalidArgumentException("Cannot build {$class}: missing `{$name}`");
                    }
                }
            }
        }

        $object = $reflection->newInstanceArgs($args);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            if (isset($used[$name]) || ! array_key_exists($name, $data) || $property->isStatic() || $property->isReadOnly()) {
                continue;
            }
            $property->setValue($object, self::cast($data[$name], $property, $property->getAttributes(ListOf::class)));
        }

        return $object;
    }

    /**
     * @param  array<\ReflectionAttribute<ListOf>>  $listOf
     */
    private static function cast(mixed $value, ReflectionParameter|ReflectionProperty $target, array $listOf): mixed
    {
        if ($value === null) {
            return null;
        }
        $type = $target->getType();
        $types = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => array_filter($type->getTypes(), fn ($t) => $t instanceof ReflectionNamedType),
            default => [],
        };

        foreach ($types as $named) {
            $name = $named->getName();
            if ($named->isBuiltin()) {
                if ($name === 'array' && is_array($value) && $listOf !== []) {
                    $item = $listOf[0]->newInstance()->class;

                    return array_map(fn ($v) => is_array($v) ? self::hydrateValue($item, $v) : self::hydrateValue($item, $v), $value);
                }
                if ($name === 'float' && is_int($value)) {
                    return (float) $value;
                }

                continue;
            }

            return self::hydrateValue($name, $value);
        }

        return $value;
    }

    private static function hydrateValue(string $class, mixed $value): mixed
    {
        if (is_object($value)) {
            return $value;
        }
        if (enum_exists($class)) {
            if (is_subclass_of($class, BackedEnum::class)) {
                return is_int($value) || is_string($value) ? $class::from($value) : $value;
            }
            foreach ($class::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }

            return $value;
        }
        if (is_a($class, DateTimeInterface::class, true)) {
            if (! is_string($value)) {
                return $value;
            }

            return $class === \DateTime::class ? new \DateTime($value) : new DateTimeImmutable($value);
        }
        if (class_exists($class) && is_array($value)) {
            return self::hydrate($class, $value);
        }

        return $value;
    }
}
