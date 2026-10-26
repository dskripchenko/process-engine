<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Durations as process authors write them: ISO 8601 (`P14D`, `PT10M`) or
 * short human strings (`14 days`, `1 minute`, `10s`, `2h`).
 */
final class Duration
{
    private const UNITS = [
        's' => 'second', 'sec' => 'second', 'secs' => 'second', 'second' => 'second', 'seconds' => 'second',
        'm' => 'minute', 'min' => 'minute', 'mins' => 'minute', 'minute' => 'minute', 'minutes' => 'minute',
        'h' => 'hour', 'hr' => 'hour', 'hrs' => 'hour', 'hour' => 'hour', 'hours' => 'hour',
        'd' => 'day', 'day' => 'day', 'days' => 'day',
        'w' => 'week', 'week' => 'week', 'weeks' => 'week',
        'month' => 'month', 'months' => 'month',
        'y' => 'year', 'year' => 'year', 'years' => 'year',
    ];

    public static function isValid(string $spec): bool
    {
        try {
            self::add(new DateTimeImmutable('2000-01-01T00:00:00+00:00'), $spec);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function add(DateTimeImmutable $from, string $spec): DateTimeImmutable
    {
        $spec = trim($spec);
        if ($spec === '') {
            throw new InvalidArgumentException('Empty duration');
        }

        if (str_starts_with($spec, 'P')) {
            try {
                return $from->add(new DateInterval($spec));
            } catch (\Exception) {
                throw new InvalidArgumentException("Invalid ISO 8601 duration `{$spec}`");
            }
        }

        $parts = preg_split('/\s*,\s*|\s+and\s+/', $spec) ?: [$spec];
        $result = $from;
        foreach ($parts as $part) {
            if (preg_match('/^(\d+)\s*([a-zA-Z]+)$/', trim($part), $m) !== 1) {
                throw new InvalidArgumentException("Invalid duration `{$spec}`: use `14 days`, `10 minutes` or ISO 8601");
            }
            $unit = self::UNITS[strtolower($m[2])] ?? null;
            if ($unit === null) {
                throw new InvalidArgumentException("Unknown duration unit `{$m[2]}` in `{$spec}`");
            }
            $result = $result->modify("+{$m[1]} {$unit}");
        }

        return $result;
    }

    /**
     * Length in seconds measured from a reference point (months and years vary).
     */
    public static function seconds(string $spec, ?DateTimeImmutable $from = null): int
    {
        $from ??= new DateTimeImmutable('2000-01-01T00:00:00+00:00');

        return self::add($from, $spec)->getTimestamp() - $from->getTimestamp();
    }
}
