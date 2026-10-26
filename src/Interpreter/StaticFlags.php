<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

/**
 * Flags from a fixed map; unknown flags are off.
 */
final class StaticFlags implements FlagProvider
{
    /**
     * @param  array<string, bool>  $flags
     */
    public function __construct(private array $flags = []) {}

    public function enabled(string $flag): bool
    {
        return $this->flags[$flag] ?? false;
    }

    public function set(string $flag, bool $on): void
    {
        $this->flags[$flag] = $on;
    }
}
