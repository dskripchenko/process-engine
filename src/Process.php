<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

use Dskripchenko\ProcessEngine\Attributes\ProcessName;
use Dskripchenko\ProcessEngine\Builder\Builder;
use LogicException;
use ReflectionClass;

/**
 * A process definition written in PHP. Subclasses declare `VERSION` and build
 * the graph in `define()`; guards and context updates are small methods of the
 * same class, referred to as `$this->method(...)`.
 *
 * The class is instantiated by the engine without constructor arguments:
 * guards and actions get no services, so they cannot do I/O. Everything that
 * touches the outside world is an effect.
 */
abstract class Process
{
    abstract public function define(Builder $p): void;

    /**
     * Mirrors of the current state into columns of host models, see `project()`.
     *
     * @return list<array<string, mixed>>
     */
    public function projections(): array
    {
        return [];
    }

    public static function processName(): string
    {
        $reflection = new ReflectionClass(static::class);
        $attribute = $reflection->getAttributes(ProcessName::class)[0] ?? null;
        if ($attribute !== null) {
            return $attribute->newInstance()->name;
        }

        $short = $reflection->getShortName();

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));
    }

    public static function processVersion(): int
    {
        $constant = static::class.'::VERSION';
        if (! defined($constant)) {
            throw new LogicException(static::class.' must declare `public const VERSION = 1;`');
        }

        return (int) constant($constant);
    }

    /**
     * The enum of state ids, when the process declares `STATES`.
     *
     * @return class-string<\BackedEnum>|null
     */
    public static function statesEnum(): ?string
    {
        $constant = static::class.'::STATES';
        if (! defined($constant)) {
            return null;
        }
        $enum = constant($constant);

        return is_string($enum) && enum_exists($enum) && is_subclass_of($enum, \BackedEnum::class) ? $enum : null;
    }
}
