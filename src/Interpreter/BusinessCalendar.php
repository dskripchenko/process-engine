<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use DateTimeImmutable;

/**
 * Counts business days. The engine knows no holidays: hosts plug their own.
 */
interface BusinessCalendar
{
    public function addBusinessDays(DateTimeImmutable $from, int $days): DateTimeImmutable;
}
