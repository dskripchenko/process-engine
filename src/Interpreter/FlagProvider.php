<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

/**
 * Host feature switches read by `flag('name')` guards.
 */
interface FlagProvider
{
    public function enabled(string $flag): bool;
}
