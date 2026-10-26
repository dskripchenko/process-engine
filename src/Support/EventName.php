<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

use Dskripchenko\ProcessEngine\Attributes\Event;
use ReflectionClass;

/**
 * The stored name of an event: a plain string, or for an event class its
 * `#[Event('name')]` or its fully qualified class name.
 */
final class EventName
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function of(string|object $event): string
    {
        $class = is_object($event) ? $event::class : $event;
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }
        if (! class_exists($class)) {
            return $class;
        }
        $attribute = (new ReflectionClass($class))->getAttributes(Event::class)[0] ?? null;

        return self::$cache[$class] = $attribute !== null ? $attribute->newInstance()->name : $class;
    }

    /**
     * Event names are either classes or registered aliases; this finds the class
     * for a name so payloads can be turned back into objects.
     */
    public static function register(string $class): void
    {
        self::$cache[$class] = self::of($class);
        EventRegistry::remember(self::$cache[$class], $class);
    }
}
