<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * One side effect in the outbox: what to call, with which semantics and
 * policy, and where it stands.
 */
final class EffectRecord
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const RECONCILING = 'reconciling';

    public const CANCELLED = 'cancelled';

    /** A compensation waiting for the ones before it. */
    public const WAITING = 'waiting';

    public const COMPENSATED = 'compensated';

    public const KIND_INVOKE = 'invoke';

    public const KIND_ACTION = 'action';

    public const KIND_POLL = 'poll';

    public const KIND_COMPENSATION = 'compensation';

    /** A chunk of a batch invocation (ADR-014); without keys — the feeder that only refills. */
    public const KIND_BATCH = 'batch';

    /**
     * @param  array<string, mixed>  $input  {context, contextClass, input, item, index, invoke, activation, state, scopes, poll}
     * @param  array<string, mixed>  $retry
     * @param  array<string, mixed>|null  $error  {code, message, class}
     */
    public function __construct(
        public readonly string $id,
        public readonly string $instanceId,
        public readonly string $key,
        public readonly string $kind,
        public readonly string $class,
        public readonly string $semantics,
        public readonly array $retry,
        public string $status,
        public array $input,
        public int $attempt = 0,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?string $lockedBy = null,
        public ?DateTimeImmutable $leaseUntil = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $finishedAt = null,
        public mixed $result = null,
        public ?array $error = null,
        public bool $compensable = false,
        public ?string $compensationOf = null,
        public int $reconcileAttempt = 0,
        public int $runs = 0,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isAtMostOnce(): bool
    {
        return $this->semantics === 'at-most-once';
    }
}
