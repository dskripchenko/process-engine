<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use DateTimeImmutable;

/**
 * The engine's only source of time (structurally compatible with PSR-20).
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
