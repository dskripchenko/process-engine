<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

use RuntimeException;
use Throwable;

/**
 * Thrown by an effect handler when the call went through and was refused
 * (a business error such as "insufficient funds"). Never retried: the process
 * receives an error event with the code.
 */
class EffectFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }
}
