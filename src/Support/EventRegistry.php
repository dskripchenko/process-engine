<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

/**
 * Event name → event class, filled while definitions are compiled, so that a
 * stored payload can be rebuilt into the class a guard or action expects.
 */
final class EventRegistry
{
    /** @var array<string, class-string> */
    private static array $classes = [];

    public static function remember(string $name, string $class): void
    {
        if (class_exists($class)) {
            self::$classes[$name] = $class;
        }
    }

    /**
     * @return class-string|null
     */
    public static function classFor(string $name): ?string
    {
        if (isset(self::$classes[$name])) {
            return self::$classes[$name];
        }

        return class_exists($name) ? $name : null;
    }
}
