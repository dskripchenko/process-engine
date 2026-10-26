<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

/**
 * The outcome of one step: the new snapshot, commands for the runtime and
 * what happened, for the journal.
 */
final class StepResult
{
    public const TRANSITIONED = 'transitioned';

    public const IGNORED = 'ignored';

    public const DEFERRED = 'deferred';

    /** A late result for an activation that is no longer active. */
    public const DISCARDED = 'discarded';

    /** One element of a forEach call recorded; the call is not complete yet. */
    public const COLLECTED = 'collected';

    /**
     * @param  list<Command>  $commands
     * @param  list<array<string, mixed>>  $transitions  taken: {source, target, event, guard?}
     * @param  list<array<string, mixed>>  $rejected  candidates whose guard was false
     * @param  list<string>  $exited
     * @param  list<string>  $entered
     * @param  list<string>  $notes
     * @param  list<string>  $expected  events the configuration accepts (when ignored)
     */
    public function __construct(
        public readonly string $outcome,
        public readonly Snapshot $snapshot,
        public readonly array $commands = [],
        public readonly array $transitions = [],
        public readonly array $rejected = [],
        public readonly array $exited = [],
        public readonly array $entered = [],
        public readonly array $notes = [],
        public readonly array $expected = [],
        public readonly ?string $reason = null,
    ) {}

    public function changed(): bool
    {
        return $this->outcome === self::TRANSITIONED || $this->outcome === self::COLLECTED;
    }
}
