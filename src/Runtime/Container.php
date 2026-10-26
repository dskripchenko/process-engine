<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Builds effect handlers (structurally compatible with PSR-11's get()).
 */
interface Container
{
    /**
     * The handler for an effect class. Usually an instance of it; a test
     * double works too, since the engine reads semantics from the class name.
     */
    public function get(string $class): object;
}
