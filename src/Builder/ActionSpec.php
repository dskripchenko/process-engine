<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

/**
 * A declarative action (`assign()`, `raise()`, `log()`), stored as data.
 */
final class ActionSpec
{
    /**
     * @param  array<string, mixed>  $action
     */
    public function __construct(public readonly array $action) {}
}
