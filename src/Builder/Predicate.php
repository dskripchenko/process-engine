<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

/**
 * A declarative guard over `$.context` and `$.event`, built by `eq()`, `all()`
 * and friends. Stored as data, evaluated without eval.
 */
final class Predicate
{
    /**
     * @param  array<string, mixed>  $expr
     */
    public function __construct(public readonly array $expr) {}

    public static function path(string $path): string
    {
        return str_starts_with($path, '$.') ? $path : '$.context.'.$path;
    }
}
