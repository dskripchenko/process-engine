<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

use DateTimeImmutable;

/**
 * What an effect handler knows about the call it is making. `$key` is the
 * idempotency key: stable across retries of the same call, new for a new
 * activation of the state. Pass it to the other side where it is supported.
 */
final class Effect
{
    public function __construct(
        public readonly string $key,
        public readonly string $instanceId,
        public readonly int $attempt,
        public readonly DateTimeImmutable $now,
        public readonly mixed $item = null,
        public readonly ?int $index = null,
        public readonly mixed $input = null,
    ) {}
}
