<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Definition;

use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Attributes\Semantics;
use Dskripchenko\ProcessEngine\Builder\Compiler;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Support\Duration;
use ReflectionClass;

/**
 * Checks a canonical definition: structure, references, durations, effect
 * semantics. Errors make the definition unusable; warnings point at likely
 * mistakes (unreachable states, choices that can get stuck).
 */
final class Validator
{
    /** @var array<string, true> */
    private static array $compiling = [];

    /**
     * @param  array<string, mixed>  $data
     * @return list<Issue>
     */
    public static function validate(array $data): array
    {
        $issues = [];
        if (($data['format'] ?? null) !== Definition::FORMAT) {
            $issues[] = Issue::error('format', 'Unknown definition format: expected '.Definition::FORMAT);
        }
        if (! is_string($data['name'] ?? null) || $data['name'] === '') {
            $issues[] = Issue::error('name', 'A definition needs a name');
        }
        if (! is_int($data['version'] ?? null) || $data['version'] < 1) {
            $issues[] = Issue::error('version', 'The version must be a positive integer');
        }

        try {
            $definition = Definition::fromArray($data);
        } catch (DefinitionError $e) {
            $issues[] = Issue::error('structure', $e->getMessage());

            return $issues;
        }

        $class = $definition->processClass();
        $key = $definition->key();
        self::$compiling[$key] = true;
        try {
            foreach ($definition->nodes() as $node) {
                array_push($issues, ...self::node($definition, $node, $class));
            }
            array_push($issues, ...self::reachability($definition));
            array_push($issues, ...self::statesEnum($definition, $data));
        } finally {
            unset(self::$compiling[$key]);
        }

        usort($issues, fn (Issue $a, Issue $b) => [$a->isError() ? 0 : 1] <=> [$b->isError() ? 0 : 1]);

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private static function node(Definition $definition, StateNode $node, ?string $class): array
    {
        $issues = [];
        $id = $node->id;

        switch ($node->type) {
            case StateType::Compound:
                if ($node->stateChildren() === []) {
                    $issues[] = Issue::error('empty-compound', 'A compound state needs child states', $id);
                }
                if ($node->initial !== null && ! in_array($node->initial, array_map(fn ($c) => $c->id, $node->children), true)) {
                    $issues[] = Issue::error('initial', "Initial state `{$node->initial}` is not a child", $id);
                }
                break;
            case StateType::Parallel:
                if ($node->stateChildren() === []) {
                    $issues[] = Issue::error('empty-parallel', 'A parallel state needs regions', $id);
                }
                break;
            case StateType::Final:
                if ($node->children !== [] || $node->on !== [] || $node->always !== [] || $node->invoke !== []) {
                    $issues[] = Issue::error('final-behaviour', 'A final state has no children, transitions or activities', $id);
                }
                if ($node->parent?->isParallel()) {
                    $issues[] = Issue::error('final-in-parallel', 'A final state cannot be a direct region of a parallel state', $id);
                }
                break;
            case StateType::Choice:
                if ($node->always === []) {
                    $issues[] = Issue::error('choice-empty', 'A choice needs branches', $id);
                } elseif (array_filter($node->always, fn (Transition $t) => $t->guard === null) === []) {
                    $issues[] = Issue::warning('choice-stuck', 'No branch without a guard (otherwise): the process can get stuck here', $id);
                }
                break;
            case StateType::History:
                if ($node->parent === null || $node->parent->isAtomic()) {
                    $issues[] = Issue::error('history-parent', 'A history state belongs to a compound or parallel state', $id);
                }
                if ($node->historyDefault !== null && ! $definition->has($node->historyDefault)) {
                    $issues[] = Issue::error('history-default', "Unknown default `{$node->historyDefault}`", $id);
                }
                break;
            case StateType::Atomic:
                break;
        }

        foreach ([...$node->on, ...$node->always] as $transition) {
            if ($transition->target !== null && ! $definition->has($transition->target)) {
                $issues[] = Issue::error('unknown-target', "Transition targets unknown state `{$transition->target}`", $id);
            }
            if ($transition->guard !== null) {
                array_push($issues, ...self::guard($transition->guard, $class, $id));
            }
            foreach ($transition->actions as $action) {
                array_push($issues, ...self::action($action, $class, $id));
            }
        }
        foreach ([...$node->entry, ...$node->exit] as $action) {
            array_push($issues, ...self::action($action, $class, $id));
        }
        foreach ($node->timers as $timer) {
            $spec = $timer['delay'] ?? $timer['at'] ?? null;
            if (! is_array($spec)) {
                $issues[] = Issue::error('timer', 'A timer needs `delay` or `at`', $id);
            } else {
                array_push($issues, ...self::spec($spec, $class, $id));
            }
        }
        foreach ($node->invoke as $invoke) {
            array_push($issues, ...self::invoke($definition, $node, $invoke, $class));
        }
        foreach ($node->polls as $poll) {
            $effect = (string) ($poll['effect'] ?? '');
            if (! class_exists($effect)) {
                $issues[] = Issue::error('unknown-effect', "Poll handler {$effect} does not exist", $id);
            } elseif ((new ReflectionClass($effect))->getAttributes(Idempotent::class) === []) {
                $issues[] = Issue::error('poll-semantics', "Poll handler {$effect} must be #[Idempotent]", $id);
            }
            if (is_array($poll['every'] ?? null)) {
                array_push($issues, ...self::spec($poll['every'], $class, $id));
            }
        }
        foreach ($node->correlate as $correlation) {
            if ($correlation['events'] === [] || $correlation['by'] === '') {
                $issues[] = Issue::error('correlate', 'A correlation needs events and `by`', $id);
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $guard
     * @return list<Issue>
     */
    private static function guard(array $guard, ?string $class, string $state): array
    {
        return match ($guard['type'] ?? null) {
            'method' => self::method((string) ($guard['name'] ?? ''), $class, $state, 'guard'),
            'predicate' => is_array($guard['expr'] ?? null) ? [] : [Issue::error('predicate', 'A predicate guard needs `expr`', $state)],
            default => [Issue::error('guard', 'Unknown guard type', $state)],
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<Issue>
     */
    private static function action(array $action, ?string $class, string $state): array
    {
        switch ($action['type'] ?? null) {
            case 'method':
                return self::method((string) ($action['name'] ?? ''), $class, $state, 'action');
            case 'effect':
                return self::effect((string) ($action['class'] ?? ''), $state);
            case 'spawn':
                $process = (string) ($action['process'] ?? '');
                $issues = is_subclass_of($process, Process::class) ? [] : [Issue::error('unknown-process', "Spawned process {$process} does not exist", $state)];
                if (isset($action['input'])) {
                    array_push($issues, ...self::method((string) $action['input'], $class, $state, 'input'));
                }

                return $issues;
            case 'assign':
                return is_array($action['set'] ?? null) ? [] : [Issue::error('assign', 'assign needs `set`', $state)];
            case 'raise':
                return is_string($action['event'] ?? null) ? [] : [Issue::error('raise', 'raise needs `event`', $state)];
            case 'compensate':
            case 'log':
                return [];
            default:
                return [Issue::error('action', 'Unknown action type `'.(string) ($action['type'] ?? '').'`', $state)];
        }
    }

    /**
     * @param  array<string, mixed>  $invoke
     * @return list<Issue>
     */
    private static function invoke(Definition $definition, StateNode $node, array $invoke, ?string $class): array
    {
        $issues = [];
        $id = $node->id;
        if (isset($invoke['batch'])) {
            array_push($issues, ...self::effect((string) $invoke['batch'], $id));
            $items = (string) ($invoke['items'] ?? '');
            if ($items === '' || ! class_exists($items)) {
                $issues[] = Issue::error('batch', "The source of a batch does not exist: `{$items}`", $id);
            }
        } elseif (isset($invoke['effect'])) {
            array_push($issues, ...self::effect((string) $invoke['effect'], $id));
        } elseif (isset($invoke['process'])) {
            $process = (string) $invoke['process'];
            if (! is_subclass_of($process, Process::class)) {
                $issues[] = Issue::error('unknown-process', "Child process {$process} does not exist", $id);
            } else {
                array_push($issues, ...self::childFinals($node, $invoke, $process));
            }
        } else {
            $issues[] = Issue::error('invoke', 'An invoke needs `effect` or `process`', $id);
        }
        if (isset($invoke['input'])) {
            array_push($issues, ...self::method((string) $invoke['input'], $class, $id, 'input'));
        }

        return $issues;
    }

    /**
     * Routing by the child's final states must name every final it has.
     *
     * @param  array<string, mixed>  $invoke
     * @return list<Issue>
     */
    private static function childFinals(StateNode $node, array $invoke, string $process): array
    {
        $event = 'done.invoke.'.$invoke['id'];
        $routed = [];
        $hasMap = false;
        foreach ($node->on as $t) {
            if ($t->event !== $event) {
                continue;
            }
            $value = $t->guard['expr']['eq'][1] ?? null;
            if (($t->guard['expr']['eq'][0] ?? null) === '$.event.final' && is_string($value)) {
                $hasMap = true;
                $routed[] = $value;
            }
        }
        if (! $hasMap) {
            return [];
        }
        /** @var class-string<Process> $process */
        $childKey = $process::processName().'@'.$process::processVersion();
        if (isset(self::$compiling[$childKey])) {
            return [];
        }
        try {
            $finals = Compiler::compile($process)->finals();
        } catch (DefinitionError) {
            return [];
        }
        $missing = array_diff($finals, $routed);
        $unknown = array_diff($routed, $finals);
        $issues = [];
        if ($missing !== []) {
            $issues[] = Issue::error('unrouted-final', "Finals of {$process} not routed: ".implode(', ', $missing), $node->id);
        }
        if ($unknown !== []) {
            $issues[] = Issue::error('unknown-final', "{$process} has no finals ".implode(', ', $unknown), $node->id);
        }

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private static function effect(string $effect, string $state): array
    {
        if (! class_exists($effect)) {
            return [Issue::error('unknown-effect', "Effect {$effect} does not exist", $state)];
        }
        foreach ((new ReflectionClass($effect))->getAttributes() as $attribute) {
            if (is_subclass_of($attribute->getName(), Semantics::class)) {
                return [];
            }
        }

        return [Issue::error('effect-semantics', "Effect {$effect} has no retry semantics: mark it #[Idempotent] or #[AtMostOnce]", $state)];
    }

    /**
     * @return list<Issue>
     */
    private static function method(string $name, ?string $class, string $state, string $role): array
    {
        if ($name === '') {
            return [Issue::error('method', "A {$role} names no method", $state)];
        }
        if ($class === null || ! class_exists($class)) {
            return [Issue::warning('method-unchecked', "The {$role} `{$name}` cannot be checked without the process class", $state)];
        }
        if (! method_exists($class, $name)) {
            return [Issue::error('unknown-method', "{$class} has no method `{$name}` (used as {$role})", $state)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<Issue>
     */
    private static function spec(array $spec, ?string $class, string $state): array
    {
        if (isset($spec['duration'])) {
            return Duration::isValid((string) $spec['duration']) ? [] : [Issue::error('duration', "Invalid duration `{$spec['duration']}`", $state)];
        }
        if (isset($spec['method'])) {
            return self::method((string) $spec['method'], $class, $state, 'duration');
        }
        if (isset($spec['path']) || isset($spec['businessDays'])) {
            return [];
        }

        return [Issue::error('spec', 'A time spec needs duration, method, path or businessDays', $state)];
    }

    /**
     * @return list<Issue>
     */
    private static function reachability(Definition $definition): array
    {
        $reached = [];
        $queue = [$definition->root];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (isset($reached[$node->id])) {
                continue;
            }
            $reached[$node->id] = true;
            // Entering a compound reaches its initial child, a parallel all regions.
            if ($node->isParallel()) {
                array_push($queue, ...$node->stateChildren());
            } elseif ($node->isCompound()) {
                $children = $node->stateChildren();
                $initial = $node->initial !== null ? $definition->node($node->initial) : ($children[0] ?? null);
                if ($initial !== null) {
                    $queue[] = $initial;
                }
            } elseif ($node->isHistory() && $node->historyDefault !== null) {
                $queue[] = $definition->node($node->historyDefault);
            }
            foreach ([...$node->on, ...$node->always] as $t) {
                if ($t->target !== null && $definition->has($t->target)) {
                    $queue[] = $definition->node($t->target);
                }
            }
            // Ancestors' transitions apply to descendants: reached through the parent chain.
            if ($node->parent !== null && ! isset($reached[$node->parent->id])) {
                $queue[] = $node->parent;
            }
        }

        $issues = [];
        foreach ($definition->nodes() as $id => $node) {
            if (! isset($reached[$id]) && ! $node->isHistory()) {
                $issues[] = Issue::warning('unreachable', 'The state cannot be reached', $id);
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<Issue>
     */
    private static function statesEnum(Definition $definition, array $data): array
    {
        $enum = $data['states'] ?? null;
        if (! is_string($enum) || ! enum_exists($enum)) {
            return [];
        }
        $issues = [];
        foreach ($enum::cases() as $case) {
            $value = $case instanceof \BackedEnum ? (string) $case->value : $case->name;
            if (! $definition->has($value)) {
                $issues[] = Issue::error('enum-undeclared', "{$enum}::{$case->name} is not declared as a state");
            }
        }

        return $issues;
    }
}
