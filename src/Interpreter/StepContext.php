<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use DateTimeImmutable;

/**
 * What a guard or an action may know besides the context and the event: the
 * time of the step (fixed for the whole step), the instance, the active states.
 */
final class StepContext
{
    /**
     * @param  list<string>  $active
     */
    public function __construct(
        private readonly DateTimeImmutable $now,
        public readonly string $instanceId = '',
        public readonly int $seq = 0,
        public array $active = [],
        public int $spawnedAlive = 0,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function isIn(string $state): bool
    {
        return in_array($state, $this->active, true);
    }

    public function spawned(): int
    {
        return $this->spawnedAlive;
    }
}
