<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

use Closure;
use ReflectionFunctionAbstract;
use ReflectionNamedType;

/**
 * Builds the argument list of a method or handler by parameter type and name,
 * so process methods and effect handlers declare only what they need:
 *
 * - a parameter typed with a class takes the provided object of that class
 *   (the context, the event object, StepContext, Effect, …) or is built from
 *   an array by a factory;
 * - `array`/untyped parameters named `context`, `event`, `item`, `result`,
 *   `input` take those values;
 * - remaining parameters take the leftover values in order.
 *
 * A parameter declared by reference (`array &$context`) gets a reference.
 */
final class Arguments
{
    /**
     * @param  array<class-string, object>  $objects  by class
     * @param  array<string, mixed>  $named  by role name, references allowed
     * @param  array<string, Closure(class-string): ?object>  $factories  role name → builds a typed object from data
     * @param  list<mixed>  $extras  for the leftover parameters
     * @return list<mixed>
     */
    public static function resolve(ReflectionFunctionAbstract $function, array $objects, array &$named, array $factories = [], array $extras = []): array
    {
        $args = [];
        $usedRoles = [];
        $pending = [];

        foreach ($function->getParameters() as $i => $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $class = $type->getName();
                $matched = false;
                foreach ($objects as $objectClass => $object) {
                    if (is_a($object, $class)) {
                        $args[$i] = $object;
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    foreach ($factories as $role => $factory) {
                        if (isset($usedRoles[$role])) {
                            continue;
                        }
                        /** @var class-string $class */
                        $built = $factory($class);
                        if ($built !== null) {
                            $args[$i] = $built;
                            $usedRoles[$role] = true;
                            $matched = true;
                            break;
                        }
                    }
                }
                if (! $matched) {
                    $args[$i] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
                }

                continue;
            }

            $role = match ($name) {
                'context', 'ctx', 'c' => 'context',
                'event', 'e', 'payload' => 'event',
                default => array_key_exists($name, $named) ? $name : null,
            };
            if ($role !== null && array_key_exists($role, $named) && ! isset($usedRoles[$role])) {
                $usedRoles[$role] = true;
                if ($parameter->isPassedByReference()) {
                    $args[$i] = &$named[$role];
                } else {
                    $args[$i] = $named[$role];
                }

                continue;
            }
            $pending[] = $i;
        }

        $leftRoles = array_values(array_filter(['context', 'event'], fn ($r) => array_key_exists($r, $named) && ! isset($usedRoles[$r])));
        foreach ($pending as $i) {
            $parameter = $function->getParameters()[$i];
            $type = $parameter->getType();
            $acceptsArray = ! $type instanceof ReflectionNamedType || in_array($type->getName(), ['array', 'mixed'], true);
            if ($acceptsArray && $leftRoles !== []) {
                if ($leftRoles[0] === 'event' && $extras !== []) {
                    // After the context, a leftover parameter is an extra (forEach item, result) when extras exist.
                    $args[$i] = array_shift($extras);

                    continue;
                }
                $role = array_shift($leftRoles);
                $usedRoles[$role] = true;
                if ($parameter->isPassedByReference()) {
                    $args[$i] = &$named[$role];
                } else {
                    $args[$i] = $named[$role];
                }

                continue;
            }
            if ($extras !== []) {
                $args[$i] = array_shift($extras);

                continue;
            }
            $args[$i] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }

        ksort($args);

        return array_values($args);
    }
}
