<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

/**
 * What a reconciliation found out about an effect whose outcome was unknown.
 */
final class Outcome
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const NOT_EXECUTED = 'not_executed';

    public const STILL_UNKNOWN = 'still_unknown';

    private function __construct(
        public readonly string $status,
        public readonly mixed $result = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    /** The call went through; `$result` is what it would have returned. */
    public static function succeeded(mixed $result = null): self
    {
        return new self(self::SUCCEEDED, $result);
    }

    /** The call went through and was refused. */
    public static function failed(string $code, ?string $message = null): self
    {
        return new self(self::FAILED, code: $code, message: $message);
    }

    /** The other side has no trace of the call: it is safe to make it again. */
    public static function notExecuted(): self
    {
        return new self(self::NOT_EXECUTED);
    }

    /** Still impossible to tell; try again later. */
    public static function stillUnknown(): self
    {
        return new self(self::STILL_UNKNOWN);
    }
}
