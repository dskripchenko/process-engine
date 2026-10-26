<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

use Closure;
use Dskripchenko\ProcessEngine\Builder\ActionSpec;
use Dskripchenko\ProcessEngine\Builder\BusinessDays;
use Dskripchenko\ProcessEngine\Builder\Predicate;
use Dskripchenko\ProcessEngine\Builder\Projection;
use Dskripchenko\ProcessEngine\Builder\StepSpec;

// Builder helpers. Paths without `$.` are context properties: `'amount'`
// means `$.context.amount`; event fields are written `'$.event.amount'`.

if (! function_exists(__NAMESPACE__.'\step')) {
    /**
     * One step of `steps()`.
     *
     * @param  class-string  $class
     */
    function step(string $class, ?string $into = null, ?string $forEach = null, ?string $id = null, ?Closure $input = null, ?int $concurrency = null): StepSpec
    {
        return new StepSpec($class, $into, $forEach, $id, $input, $concurrency);
    }

    function eq(string $path, mixed $value): Predicate
    {
        return new Predicate(['eq' => [Predicate::path($path), $value]]);
    }

    function ne(string $path, mixed $value): Predicate
    {
        return new Predicate(['ne' => [Predicate::path($path), $value]]);
    }

    function gt(string $path, int|float $value): Predicate
    {
        return new Predicate(['gt' => [Predicate::path($path), $value]]);
    }

    function gte(string $path, int|float $value): Predicate
    {
        return new Predicate(['gte' => [Predicate::path($path), $value]]);
    }

    function lt(string $path, int|float $value): Predicate
    {
        return new Predicate(['lt' => [Predicate::path($path), $value]]);
    }

    function lte(string $path, int|float $value): Predicate
    {
        return new Predicate(['lte' => [Predicate::path($path), $value]]);
    }

    /**
     * @param  list<mixed>  $values
     */
    function in(string $path, array $values): Predicate
    {
        return new Predicate(['in' => [Predicate::path($path), $values]]);
    }

    function exists(string $path): Predicate
    {
        return new Predicate(['exists' => Predicate::path($path)]);
    }

    function isEmpty(string $path): Predicate
    {
        return new Predicate(['empty' => Predicate::path($path)]);
    }

    function all(Predicate ...$predicates): Predicate
    {
        return new Predicate(['all' => array_map(fn (Predicate $p) => $p->expr, $predicates)]);
    }

    function any(Predicate ...$predicates): Predicate
    {
        return new Predicate(['any' => array_map(fn (Predicate $p) => $p->expr, $predicates)]);
    }

    function not(Predicate $predicate): Predicate
    {
        return new Predicate(['not' => $predicate->expr]);
    }

    /**
     * A host feature switch (FlagProvider): turns a branch on or off without
     * a new version.
     */
    function flag(string $name): Predicate
    {
        return new Predicate(['flag' => $name]);
    }

    /**
     * Set a context property: a literal, `fromEvent('field')` or `inc(1)`.
     */
    function assign(string $path, mixed $value): ActionSpec
    {
        $spec = match (true) {
            $value instanceof ValueSpec => $value->spec,
            default => ['value' => $value],
        };

        return new ActionSpec(['type' => 'assign', 'set' => [Predicate::path($path) => $spec]]);
    }

    function fromEvent(string $field): ValueSpec
    {
        return new ValueSpec(['path' => str_starts_with($field, '$.') ? $field : '$.event.'.$field]);
    }

    function inc(int|float $by = 1): ValueSpec
    {
        return new ValueSpec(['inc' => $by]);
    }

    /**
     * Raise an internal event, processed in the same step.
     *
     * @param  array<string, mixed>  $payload
     */
    function raise(string $event, array $payload = []): ActionSpec
    {
        return new ActionSpec(['type' => 'raise', 'event' => $event, 'payload' => $payload]);
    }

    /**
     * Write a line into the instance journal.
     */
    function note(string $message): ActionSpec
    {
        return new ActionSpec(['type' => 'log', 'message' => $message]);
    }

    function businessDays(int $days): BusinessDays
    {
        return new BusinessDays($days);
    }

    /**
     * Mirror the current state into a column of the bound host model.
     *
     * @param  array<string, string|int|\BackedEnum>  $map
     */
    function project(string $model, string $column, array $map, ?string $using = null): Projection
    {
        return new Projection($model, $column, $map, $using);
    }
}
