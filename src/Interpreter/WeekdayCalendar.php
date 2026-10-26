<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use DateTimeImmutable;

/**
 * The default calendar: Monday to Friday, no holidays.
 */
final class WeekdayCalendar implements BusinessCalendar
{
    public function addBusinessDays(DateTimeImmutable $from, int $days): DateTimeImmutable
    {
        $date = $from;
        $added = 0;
        while ($added < $days) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                $added++;
            }
        }

        return $date;
    }
}
