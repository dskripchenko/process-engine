<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Attributes;

use Attribute;
use Dskripchenko\ProcessEngine\Effect\Retry;

/**
 * The effect is safe to repeat: it runs at least once and is retried on any
 * ambiguous failure under its retry policy.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Idempotent implements Semantics
{
    /**
     * @param  list<class-string<\Throwable>|string>  $nonRetryable  exception classes or error codes that are never retried
     */
    public function __construct(
        public readonly ?Retry $retry = null,
        public readonly array $nonRetryable = [],
    ) {}

    public function name(): string
    {
        return 'idempotent';
    }

    public function retry(): Retry
    {
        return $this->retry ?? Retry::idempotentDefault();
    }

    public function nonRetryable(): array
    {
        return $this->nonRetryable;
    }
}
