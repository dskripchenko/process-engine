<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use InvalidArgumentException;

/**
 * Reads paths (`$.context.a.b`, `$.event.x`) and evaluates predicates of the
 * declarative guard language: eq ne gt gte lt lte in exists empty all any not flag.
 */
final class Expression
{
    /**
     * @param  array<string, mixed>  $scope  ['context' => [...], 'event' => [...]]
     */
    public static function read(string $path, array $scope): mixed
    {
        [$found, $value] = self::lookup($path, $scope);

        return $found ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: bool, 1: mixed}
     */
    public static function lookup(string $path, array $scope): array
    {
        if (! str_starts_with($path, '$.')) {
            throw new InvalidArgumentException("A path starts with `\$.`: `{$path}`");
        }
        $node = $scope;
        foreach (explode('.', substr($path, 2)) as $segment) {
            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
            } elseif (is_object($node) && isset($node->{$segment})) {
                $node = $node->{$segment};
            } else {
                return [false, null];
            }
        }

        return [true, $node];
    }

    /**
     * Set a `$.context.…` path in a context array.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function write(array $context, string $path, mixed $value): array
    {
        if (! str_starts_with($path, '$.context.')) {
            throw new InvalidArgumentException("Only context paths can be written: `{$path}`");
        }
        $segments = explode('.', substr($path, strlen('$.context.')));
        $ref = &$context;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                break;
            }
            if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        unset($ref);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $expr
     * @param  array<string, mixed>  $scope
     */
    public static function test(array $expr, array $scope, ?FlagProvider $flags = null): bool
    {
        if ($expr === []) {
            return false;
        }
        $op = array_key_first($expr);
        $args = $expr[$op];

        return match ($op) {
            'eq' => self::value($args[0] ?? null, $scope) == self::value($args[1] ?? null, $scope),
            'ne' => self::value($args[0] ?? null, $scope) != self::value($args[1] ?? null, $scope),
            'gt' => self::value($args[0] ?? null, $scope) > self::value($args[1] ?? null, $scope),
            'gte' => self::value($args[0] ?? null, $scope) >= self::value($args[1] ?? null, $scope),
            'lt' => self::value($args[0] ?? null, $scope) < self::value($args[1] ?? null, $scope),
            'lte' => self::value($args[0] ?? null, $scope) <= self::value($args[1] ?? null, $scope),
            'in' => in_array(self::value($args[0] ?? null, $scope), (array) self::value($args[1] ?? [], $scope), false),
            'exists' => is_string($args) && self::lookup($args, $scope)[0] && self::read($args, $scope) !== null,
            'empty' => is_string($args) && empty(self::read($args, $scope)),
            'all' => array_reduce((array) $args, fn (bool $carry, $e) => $carry && is_array($e) && self::test($e, $scope, $flags), true),
            'any' => array_reduce((array) $args, fn (bool $carry, $e) => $carry || (is_array($e) && self::test($e, $scope, $flags)), false),
            'not' => is_array($args) && ! self::test($args, $scope, $flags),
            'flag' => $flags !== null && is_string($args) && $flags->enabled($args),
            default => throw new InvalidArgumentException('Unknown predicate operator `'.(string) $op.'`'),
        };
    }

    /**
     * Whether a predicate reads the event (its outcome is unknown before the event is sent).
     *
     * @param  array<string, mixed>  $expr
     */
    public static function readsEvent(array $expr): bool
    {
        return str_contains(json_encode($expr) ?: '', '$.event');
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private static function value(mixed $operand, array $scope): mixed
    {
        if (is_string($operand) && str_starts_with($operand, '$.')) {
            return self::read($operand, $scope);
        }

        return $operand;
    }
}
