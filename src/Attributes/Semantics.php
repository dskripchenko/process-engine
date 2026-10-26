<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Attributes;

use Dskripchenko\ProcessEngine\Effect\Retry;

/**
 * The retry semantics of an effect class: `#[Idempotent]` or `#[AtMostOnce]`.
 */
interface Semantics
{
    public function name(): string;

    public function retry(): Retry;

    /**
     * @return list<string>
     */
    public function nonRetryable(): array;
}
