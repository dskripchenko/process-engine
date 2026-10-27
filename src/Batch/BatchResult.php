<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Batch;

/**
 * What a chunk handler returns: the outcome of each of its keys. A key left
 * out counts as failed ("no outcome").
 */
final class BatchResult
{
    /**
     * @param  array<string, ItemOutcome>  $outcomes
     */
    private function __construct(public readonly array $outcomes) {}

    /**
     * @param  array<string|int, ItemOutcome>  $outcomes  key => outcome
     */
    public static function of(array $outcomes): self
    {
        $normalized = [];
        foreach ($outcomes as $key => $outcome) {
            $normalized[(string) $key] = $outcome;
        }

        return new self($normalized);
    }

    /**
     * Every key done.
     *
     * @param  list<string|int>  $keys
     */
    public static function allDone(array $keys): self
    {
        return self::of(array_fill_keys(array_map('strval', $keys), ItemOutcome::done()));
    }
}
