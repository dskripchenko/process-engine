<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Support\Duration;

/**
 * A clock that moves only when told: `advance('3 days')`.
 */
final class VirtualClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string|DateTimeImmutable $now = '2026-01-05T09:00:00+00:00')
    {
        $this->now = is_string($now) ? new DateTimeImmutable($now) : $now;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $duration): void
    {
        $this->now = Duration::add($this->now, $duration);
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
