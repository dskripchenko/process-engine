<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

/**
 * An event that checks its own data before it is queued: a failing check
 * throws (any exception) and the sender gets it at once, so invalid input
 * never reaches the process.
 */
interface SelfValidating
{
    public function validate(): void;
}
