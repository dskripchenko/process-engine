<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Batch;

/**
 * The outcome of one element of a batch.
 */
final class ItemOutcome
{
    public const DONE = 'done';

    public const NOT_FOUND = 'not_found';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const DEFERRED = 'deferred';

    private function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {}

    public static function done(): self
    {
        return new self(self::DONE);
    }

    public static function notFound(?string $reason = null): self
    {
        return new self(self::NOT_FOUND, $reason);
    }

    public static function skipped(?string $reason = null): self
    {
        return new self(self::SKIPPED, $reason);
    }

    /** Tried again in later chunks while it has attempts left. */
    public static function failed(string $reason): self
    {
        return new self(self::FAILED, $reason);
    }

    /** Left for later: not tried again in this batch. */
    public static function deferred(string $reason): self
    {
        return new self(self::DEFERRED, $reason);
    }
}
