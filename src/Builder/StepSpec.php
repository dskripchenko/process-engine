<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

use Closure;

/**
 * One step of `steps()`: an effect (or a child process) with its options.
 */
final class StepSpec
{
    /**
     * @param  class-string  $class
     */
    public function __construct(
        public readonly string $class,
        public readonly ?string $into = null,
        public readonly ?string $forEach = null,
        public readonly ?string $id = null,
        public readonly ?Closure $input = null,
        public readonly ?int $concurrency = null,
    ) {}
}
