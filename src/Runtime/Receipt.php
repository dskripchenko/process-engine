<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * The answer to sending or publishing an event.
 */
final class Receipt
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const BUFFERED = 'buffered';

    public const REJECTED = 'rejected';

    /**
     * @param  list<string>  $instances  instances the event was queued for
     * @param  list<string>  $expected  for a rejected strict send
     */
    public function __construct(
        public readonly string $status,
        public readonly array $instances = [],
        public readonly array $expected = [],
    ) {}

    public function accepted(): bool
    {
        return $this->status === self::ACCEPTED || $this->status === self::BUFFERED;
    }
}
