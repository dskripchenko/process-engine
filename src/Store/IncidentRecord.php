<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * A point where the process needs a person: an effect out of retries, an
 * unknown outcome, a failed compensation, a missing handler, …
 */
final class IncidentRecord
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const EFFECT_EXHAUSTED = 'effect_exhausted';

    public const EFFECT_FAILED = 'effect_failed';

    public const EFFECT_UNKNOWN = 'effect_unknown';

    public const COMPENSATION_FAILED = 'compensation_failed';

    public const COMPENSATION_BLOCKED = 'compensation_blocked';

    public const MICROSTEP_LIMIT = 'microstep_limit';

    public const STEP_ERROR = 'step_error';

    public const HANDLER_MISSING = 'handler_missing';

    public const CONTEXT_TOO_LARGE = 'context_too_large';

    public const POLL_FAILING = 'poll_failing';

    public const MIGRATION_FAILED = 'migration_failed';

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $resolvedBy
     */
    public function __construct(
        public readonly string $id,
        public readonly string $instanceId,
        public readonly string $type,
        public readonly string $message,
        public readonly array $details,
        public readonly ?string $effectId,
        public string $status,
        public readonly DateTimeImmutable $createdAt,
        public ?string $resolution = null,
        public ?array $resolvedBy = null,
        public ?DateTimeImmutable $resolvedAt = null,
    ) {}
}
