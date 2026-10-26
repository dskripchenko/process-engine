<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

/**
 * A delay counted in business days by the host's BusinessCalendar.
 */
final class BusinessDays
{
    public function __construct(public readonly int $days) {}
}
