<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

use RuntimeException;
use Throwable;

/**
 * Thrown by an effect handler when the call certainly did not reach the other
 * side (connection refused before sending). Safe to retry for any semantics.
 */
class EffectNotExecuted extends RuntimeException
{
    public function __construct(string|Throwable $reason = 'The effect was not executed', ?Throwable $previous = null)
    {
        if ($reason instanceof Throwable) {
            parent::__construct($reason->getMessage(), 0, $reason);
        } else {
            parent::__construct($reason, 0, $previous);
        }
    }
}
