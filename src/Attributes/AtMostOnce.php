<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Attributes;

use Attribute;
use Dskripchenko\ProcessEngine\Effect\Retry;

/**
 * The effect must not run twice (a payment, a record created on the other
 * side). When it is not known whether it ran, the effect becomes `unknown`:
 * no automatic retry, only reconciliation or an operator decision. Only
 * EffectNotExecuted failures are retried.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AtMostOnce implements Semantics
{
    public function __construct(
        public readonly ?Retry $retry = null,
        public readonly ?Retry $reconcileRetry = null,
    ) {}

    public function name(): string
    {
        return 'at-most-once';
    }

    public function retry(): Retry
    {
        return $this->retry ?? Retry::atMostOnceDefault();
    }

    public function nonRetryable(): array
    {
        return [];
    }

    public function reconcileRetry(): Retry
    {
        return $this->reconcileRetry ?? Retry::reconcileDefault();
    }
}
