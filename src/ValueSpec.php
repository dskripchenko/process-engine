<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

/**
 * A value source for `assign()`: an event field or an increment.
 */
final class ValueSpec
{
    /**
     * @param  array<string, mixed>  $spec
     */
    public function __construct(public readonly array $spec) {}
}
