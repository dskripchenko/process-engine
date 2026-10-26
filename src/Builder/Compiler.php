<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

use BackedEnum;
use Closure;
use Dskripchenko\ProcessEngine\Attributes\Semantics;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Definition\Validator;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Support\EventName;
use ReflectionClass;
use ReflectionFunction;

/**
 * Turns a Process class into its canonical definition: runs `define()` on a
 * root Builder, expands sugar, validates, and compiles the result.
 *
 * @internal shared state of the builders of one process
 */
final class Compiler
{
    /** @var array<string, Definition> */
    private static array $cache = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var list<array{from: list<string>, transition: array<string, mixed>}> */
    private array $fromTransitions = [];

    private function __construct(public readonly Process $process) {}

    /**
     * @param  class-string<Process>  $class
     */
    public static function compile(string $class): Definition
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }
        if (! is_subclass_of($class, Process::class)) {
            throw new DefinitionError("{$class} does not extend ".Process::class);
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new DefinitionError("{$class} must be constructible without arguments: guards and actions get no services");
        }

        $process = $reflection->newInstance();
        $compiler = new self($process);
        $root = ['id' => Definition::ROOT, 'type' => 'compound'];
        $builder = new Builder($compiler, $root);
        $process->define($builder);
        $root = $builder->toArray();
        $root = $compiler->attachFromTransitions($root);

        $data = [
            'format' => Definition::FORMAT,
            'name' => $class::processName(),
            'version' => $class::processVersion(),
            'class' => $class,
            'root' => $root,
        ];
        $enum = $class::statesEnum();
        if ($enum !== null) {
            $data['states'] = $enum;
        }
        $projections = array_map(
            fn ($p) => $p instanceof Projection ? $p->toArray() : $p,
            $process->projections(),
        );
        if ($projections !== []) {
            $data['projections'] = array_values($projections);
        }

        $issues = Validator::validate($data);
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                throw DefinitionError::fromIssues($data['name'], $issues);
            }
        }

        return self::$cache[$class] = Definition::fromArray($data);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    public function nextId(string $prefix): string
    {
        $n = $this->counters[$prefix] = ($this->counters[$prefix] ?? 0) + 1;

        return $n === 1 ? $prefix : $prefix.$n;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $transition
     */
    public function addFromTransition(array $from, array $transition): void
    {
        $this->fromTransitions[] = ['from' => $from, 'transition' => $transition];
    }

    public static function id(string|BackedEnum $id): string
    {
        return $id instanceof BackedEnum ? (string) $id->value : $id;
    }

    public static function target(string|BackedEnum|null $target): ?string
    {
        return $target === null ? null : self::id($target);
    }

    /**
     * A reference to a method of the process: `$this->method(...)`.
     */
    public function methodName(Closure $closure, string $role): string
    {
        $reflection = new ReflectionFunction($closure);
        $name = $reflection->getName();
        $bound = $reflection->getClosureThis();
        if (str_starts_with($name, '{closure') || $bound !== $this->process) {
            throw new DefinitionError(
                "The {$role} must be a method of the process written as \$this->method(...): "
                .'anonymous closures cannot be stored or versioned',
            );
        }

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    public function guard(Closure|Predicate $guard): array
    {
        if ($guard instanceof Predicate) {
            return ['type' => 'predicate', 'expr' => $guard->expr];
        }

        return ['type' => 'method', 'name' => $this->methodName($guard, 'guard')];
    }

    /**
     * Actions from `run:`, `entry()` and `exit()`: a method of the process,
     * an effect class, a declarative ActionSpec, or a list of those.
     *
     * @return list<array<string, mixed>>
     */
    public function actions(mixed $run, string $owner): array
    {
        if ($run === null) {
            return [];
        }
        if (is_array($run)) {
            $out = [];
            foreach ($run as $item) {
                array_push($out, ...$this->actions($item, $owner));
            }

            return $out;
        }
        if ($run instanceof Closure) {
            return [['type' => 'method', 'name' => $this->methodName($run, 'action')]];
        }
        if ($run instanceof ActionSpec) {
            return [$run->action];
        }
        if (is_string($run) && class_exists($run)) {
            if (is_subclass_of($run, Process::class)) {
                throw new DefinitionError("A process class ({$run}) cannot be an action: use invoke() or spawn:");
            }
            self::assertEffect($run);

            return [['type' => 'effect', 'class' => $run, 'id' => $this->nextId($owner.'.'.self::short($run))]];
        }

        throw new DefinitionError('Unsupported action: '.get_debug_type($run).(is_string($run) ? " `{$run}` (no such class)" : ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function delay(string|Closure|BusinessDays $delay): array
    {
        return match (true) {
            $delay instanceof Closure => ['method' => $this->methodName($delay, 'delay')],
            $delay instanceof BusinessDays => ['businessDays' => $delay->days],
            default => ['duration' => $delay],
        };
    }

    public static function event(string $event): string
    {
        return EventName::of($event);
    }

    public static function short(string $class): string
    {
        $pos = strrpos($class, '\\');

        return lcfirst($pos === false ? $class : substr($class, $pos + 1));
    }

    public static function assertEffect(string $class): void
    {
        if (! class_exists($class)) {
            throw new DefinitionError("Effect class {$class} does not exist");
        }
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getAttributes() as $attribute) {
            if (is_subclass_of($attribute->getName(), Semantics::class)) {
                return;
            }
        }

        throw new DefinitionError(
            "Effect {$class} has no retry semantics: mark it #[Idempotent] or #[AtMostOnce]",
        );
    }

    /**
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>
     */
    private function attachFromTransitions(array $root): array
    {
        foreach ($this->fromTransitions as ['from' => $from, 'transition' => $transition]) {
            if ($from === ['*']) {
                $root['on'][] = $transition;

                continue;
            }
            foreach ($from as $source) {
                if (! $this->attachTo($root, $source, $transition)) {
                    throw new DefinitionError("`from: {$source}` names a state that is not declared");
                }
            }
        }

        return $root;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $transition
     */
    private function attachTo(array &$node, string $id, array $transition): bool
    {
        if (($node['id'] ?? null) === $id) {
            $node['on'][] = $transition;

            return true;
        }
        foreach ($node['states'] ?? [] as $i => $child) {
            if ($this->attachTo($child, $id, $transition)) {
                $node['states'][$i] = $child;

                return true;
            }
        }

        return false;
    }
}
