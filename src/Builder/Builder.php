<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

use BackedEnum;
use Closure;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Support\EventName;

/**
 * Builds one state of a process (the root included). Every method returns the
 * builder of the same state, so siblings and options chain:
 *
 *     $p->state('review', fn (Builder $s) => $s
 *         ->invoke(SubmitToInsurer::class, into: 'externalId', onDone: 'awaiting')
 *         ->state('awaiting', fn (Builder $s) => $s->on(PartyDecision::class, to: 'approved')));
 *
 * The result is the canonical array of the state (see Definition).
 */
final class Builder
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function __construct(private readonly Compiler $compiler, private array $node) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->node;
    }

    public function id(): string
    {
        return (string) $this->node['id'];
    }

    // States ------------------------------------------------------------------

    /**
     * A state; with a body it is compound (its first child is the initial one).
     *
     * @param  array<string, mixed>  $meta
     */
    public function state(string|BackedEnum $id, ?Closure $body = null, array $meta = []): self
    {
        $child = new self($this->compiler, ['id' => Compiler::id($id), 'type' => 'atomic']);
        if ($meta !== []) {
            $child->node['meta'] = $meta;
        }
        if ($body !== null) {
            $body($child);
        }
        $child->settleType();
        $this->addChild($child->node);

        return $this;
    }

    /**
     * Regions active at the same time. `join: all` finishes when every region
     * reaches a final state, `join: any` when the first one does.
     *
     * @param  array<string, mixed>  $meta
     */
    public function parallel(string|BackedEnum $id, Closure $body, string $join = 'all', array $meta = []): self
    {
        $child = new self($this->compiler, ['id' => Compiler::id($id), 'type' => 'parallel', 'join' => $join]);
        if ($meta !== []) {
            $child->node['meta'] = $meta;
        }
        $body($child);
        $this->addChild($child->node);

        return $this;
    }

    /**
     * A region of a parallel state: a compound state of its own.
     */
    public function region(string|BackedEnum $id, Closure $body): self
    {
        if (($this->node['type'] ?? null) !== 'parallel') {
            throw new DefinitionError('region() belongs inside parallel()');
        }

        return $this->state($id, $body);
    }

    /**
     * A final state. `output` is a context property (`'files'`) or a method
     * of the process returning the value handed to the parent or the caller.
     *
     * @param  array<string, mixed>  $meta
     */
    public function final(string|BackedEnum $id, string|Closure|null $output = null, array $meta = []): self
    {
        $node = ['id' => Compiler::id($id), 'type' => 'final'];
        if ($output !== null) {
            $node['output'] = $output instanceof Closure
                ? ['method' => $this->compiler->methodName($output, 'output')]
                : ['path' => Predicate::path($output)];
        }
        if ($meta !== []) {
            $node['meta'] = $meta;
        }
        $this->addChild($node);

        return $this;
    }

    /**
     * A branching point: only eventless transitions, the first whose guard
     * holds is taken.
     *
     * @param  array<string, mixed>  $meta
     */
    public function choice(string|BackedEnum $id, array $meta = []): ChoiceBuilder
    {
        $node = ['id' => Compiler::id($id), 'type' => 'choice', 'always' => []];
        if ($meta !== []) {
            $node['meta'] = $meta;
        }
        $index = $this->addChild($node);

        return new ChoiceBuilder($this->compiler, function (array $transitions) use ($index): void {
            $this->node['states'][$index]['always'] = $transitions;
        });
    }

    /**
     * A history pseudo-state: entering it restores the last configuration of
     * the parent (`deep` restores nested states too).
     */
    public function history(string|BackedEnum $id, bool $deep = false, string|BackedEnum|null $default = null): self
    {
        $node = ['id' => Compiler::id($id), 'type' => 'history', 'history' => $deep ? 'deep' : 'shallow'];
        if ($default !== null) {
            $node['default'] = Compiler::id($default);
        }
        $this->addChild($node);

        return $this;
    }

    public function initial(string|BackedEnum $id): self
    {
        $this->node['initial'] = Compiler::id($id);

        return $this;
    }

    /**
     * A chain of effects: one generated state per step, each moving to the
     * next when its effect is done.
     *
     * @param  list<StepSpec|class-string>  $steps
     * @param  string|BackedEnum|array<string, string|BackedEnum>|null  $onError
     * @param  array<string, mixed>  $meta
     */
    public function steps(
        string|BackedEnum $id,
        array $steps,
        string|BackedEnum|null $onDone = null,
        string|BackedEnum|array|null $onError = null,
        array $meta = [],
    ): self {
        $chainId = Compiler::id($id);
        if ($steps === []) {
            throw new DefinitionError("steps('{$chainId}') needs at least one step");
        }
        $specs = array_map(fn ($s) => is_string($s) ? new StepSpec($s) : $s, $steps);
        $ids = [];
        foreach ($specs as $spec) {
            $ids[] = $spec->id ?? $this->compiler->nextId($chainId.'_'.Compiler::short($spec->class));
        }
        $doneId = $chainId.'__done';

        return $this->state($chainId, function (Builder $chain) use ($specs, $ids, $doneId, $onError, $onDone): void {
            foreach ($specs as $i => $spec) {
                $next = $ids[$i + 1] ?? $doneId;
                $chain->state($ids[$i], fn (Builder $s) => $s->invoke(
                    $spec->class,
                    into: $spec->into,
                    input: $spec->input,
                    forEach: $spec->forEach,
                    concurrency: $spec->concurrency,
                    onDone: $next,
                    onError: $onError,
                ));
            }
            $chain->final($doneId);
            if ($onDone !== null) {
                $chain->onDone(to: $onDone);
            }
        }, $meta);
    }

    // Transitions -------------------------------------------------------------

    /**
     * A transition on an event (a name or an event class).
     *
     * - `to:` the target state; without it the transition is internal.
     * - `when:` a guard (a method of the process or a predicate).
     * - `run:` actions: methods of the process, effect classes, assign()/raise().
     * - `from:` (root only) source states, or `'*'` for any unfinished state.
     * - `match:` + `targets:` a routing table on an event field; `otherwise:` the fallback.
     * - `spawn:` start a child process per event, deduplicated by the event field `key:`.
     *
     * @param  string|list<string>  $event
     * @param  string|list<string|BackedEnum>|null  $from
     * @param  array<string|int, string|BackedEnum>|null  $targets
     */
    public function on(
        string|array $event,
        string|BackedEnum|null $to = null,
        Closure|Predicate|null $when = null,
        mixed $run = null,
        ?string $label = null,
        string|array|null $from = null,
        ?string $match = null,
        ?array $targets = null,
        string|BackedEnum|null $otherwise = null,
        ?string $spawn = null,
        ?Closure $input = null,
        ?string $key = null,
        string $onScopeExit = 'cancel',
    ): self {
        if (is_array($event)) {
            foreach ($event as $e) {
                $this->on($e, $to, $when, $run, $label, $from, $match, $targets, $otherwise, $spawn, $input, $key, $onScopeExit);
            }

            return $this;
        }
        $name = $this->eventName($event);

        $transitions = [];
        if ($match !== null) {
            foreach ($targets ?? [] as $value => $target) {
                $transitions[] = $this->transition($name, $target, new Predicate(['eq' => ['$.event.'.$match, $value]]), $run, $label);
            }
            if ($otherwise !== null) {
                $transitions[] = $this->transition($name, $otherwise, null, $run, $label);
            }
        } else {
            $transition = $this->transition($name, $to, $when, $run, $label);
            if ($spawn !== null) {
                $transition['actions'][] = $this->spawnAction($spawn, $input, $key, $onScopeExit);
            }
            $transitions[] = $transition;
        }

        if ($from !== null) {
            if (($this->node['id'] ?? null) !== Definition::ROOT) {
                throw new DefinitionError('`from:` is only available on the process itself ($p->on(...))');
            }
            $sources = $from === '*' ? ['*'] : array_map(fn ($s) => Compiler::id($s), (array) $from);
            foreach ($transitions as $transition) {
                $this->compiler->addFromTransition($sources, $transition);
            }

            return $this;
        }

        foreach ($transitions as $transition) {
            $this->node['on'][] = $transition;
        }

        return $this;
    }

    /**
     * An eventless transition, taken as soon as its guard holds.
     */
    public function always(string|BackedEnum|null $to = null, Closure|Predicate|null $when = null, mixed $run = null): self
    {
        $transition = $this->transition(null, $to, $when, $run, null);
        unset($transition['event']);
        $this->node['always'][] = $transition;

        return $this;
    }

    /**
     * A transition when the state has been active for `$delay`: `'14 days'`,
     * `'PT2H'`, `businessDays(5)` or a method returning a duration.
     */
    public function after(string|Closure|BusinessDays $delay, string|BackedEnum|null $to = null, mixed $run = null, Closure|Predicate|null $when = null): self
    {
        $timerId = $this->compiler->nextId($this->id().'.after');
        $this->node['timers'][] = ['id' => $timerId, 'delay' => $this->compiler->delay($delay)];
        $this->node['on'][] = $this->transition('after.'.$timerId, $to, $when, $run, null);

        return $this;
    }

    /**
     * A transition at a moment: a context property holding an ISO 8601 date
     * (`'deadline'`) or a method returning one.
     */
    public function at(string|Closure $moment, string|BackedEnum|null $to = null, mixed $run = null, Closure|Predicate|null $when = null): self
    {
        $timerId = $this->compiler->nextId($this->id().'.at');
        $spec = $moment instanceof Closure
            ? ['method' => $this->compiler->methodName($moment, 'moment')]
            : ['path' => Predicate::path($moment)];
        $this->node['timers'][] = ['id' => $timerId, 'at' => $spec];
        $this->node['on'][] = $this->transition('after.'.$timerId, $to, $when, $run, null);

        return $this;
    }

    /**
     * When this compound or parallel state reaches its final state.
     */
    public function onDone(string|BackedEnum|null $to = null, Closure|Predicate|null $when = null, mixed $run = null): self
    {
        $this->node['on'][] = $this->transition('done.state.'.$this->id(), $to, $when, $run, null);

        return $this;
    }

    // Activities --------------------------------------------------------------

    public function entry(mixed ...$actions): self
    {
        $this->node['entry'] ??= [];
        foreach ($actions as $action) {
            array_push($this->node['entry'], ...$this->compiler->actions($action, $this->id().'.entry'));
        }

        return $this;
    }

    public function exit(mixed ...$actions): self
    {
        $this->node['exit'] ??= [];
        foreach ($actions as $action) {
            array_push($this->node['exit'], ...$this->compiler->actions($action, $this->id().'.exit'));
        }

        return $this;
    }

    /**
     * An activity that lives while the state is active: an effect class or a
     * child process class.
     *
     * - `into:` context property that receives the result.
     * - `input:` a method of the process building the input (the child's
     *   context, or `Effect::$input`).
     * - `forEach:` a context property holding a list: one call per element,
     *   results collected in order.
     * - `onDone:` a target, or for a child process a map final state → target.
     * - `onError:` a target, or a map error code → target (`'*'` for the rest).
     * - `onUnknown:` where to go when an at-most-once effect's outcome is
     *   unknown (by default the process waits for reconciliation or an operator).
     *
     * @param  class-string  $class
     * @param  string|BackedEnum|array<string, string|BackedEnum>|null  $onDone
     * @param  string|BackedEnum|array<string, string|BackedEnum>|null  $onError
     */
    public function invoke(
        string $class,
        ?string $into = null,
        ?Closure $input = null,
        ?string $forEach = null,
        ?int $concurrency = null,
        string|BackedEnum|array|null $onDone = null,
        string|BackedEnum|array|null $onError = null,
        string|BackedEnum|null $onUnknown = null,
        ?Retry $retry = null,
        ?string $id = null,
    ): self {
        if (! class_exists($class)) {
            throw new DefinitionError("invoke(): class {$class} does not exist");
        }
        $isProcess = is_subclass_of($class, Process::class);
        if (! $isProcess) {
            Compiler::assertEffect($class);
        }
        $invokeId = $id ?? $this->compiler->nextId($this->id().'.'.Compiler::short($class));

        $invoke = ['id' => $invokeId, $isProcess ? 'process' : 'effect' => $class];
        if ($into !== null) {
            $invoke['into'] = Predicate::path($into);
        }
        if ($input !== null) {
            $invoke['input'] = $this->compiler->methodName($input, 'input');
        }
        if ($forEach !== null) {
            $invoke['forEach'] = Predicate::path($forEach);
        }
        if ($concurrency !== null) {
            $invoke['concurrency'] = $concurrency;
        }
        if ($retry !== null) {
            $invoke['retry'] = $retry->toArray();
        }
        $this->node['invoke'][] = $invoke;

        $assign = $into !== null
            ? [['type' => 'assign', 'set' => [Predicate::path($into) => ['path' => '$.event.output']]]]
            : [];

        $doneEvent = 'done.invoke.'.$invokeId;
        if (is_array($onDone)) {
            if (! $isProcess) {
                throw new DefinitionError("invoke({$class}): an effect has one outcome, a map of finals needs a child process");
            }
            foreach ($onDone as $final => $target) {
                $t = $this->transition($doneEvent, $target, new Predicate(['eq' => ['$.event.final', (string) $final]]), null, null);
                $t['actions'] = $assign;
                $t['generated'] = 'final';
                $this->node['on'][] = $t;
            }
        } elseif ($onDone !== null || $assign !== []) {
            $t = $this->transition($doneEvent, $onDone, null, null, null);
            $t['actions'] = $assign;
            $this->node['on'][] = $t;
        }

        $errorEvent = 'error.invoke.'.$invokeId;
        if (is_array($onError)) {
            $fallback = null;
            foreach ($onError as $code => $target) {
                if ($code === '*') {
                    $fallback = $target;

                    continue;
                }
                $this->node['on'][] = $this->transition($errorEvent, $target, new Predicate(['eq' => ['$.event.code', (string) $code]]), null, null);
            }
            if ($fallback !== null) {
                $this->node['on'][] = $this->transition($errorEvent, $fallback, null, null, null);
            }
        } elseif ($onError !== null) {
            $this->node['on'][] = $this->transition($errorEvent, $onError, null, null, null);
        }

        if ($onUnknown !== null) {
            $this->node['on'][] = $this->transition('unknown.invoke.'.$invokeId, $onUnknown, null, null, null);
        }

        return $this;
    }

    /**
     * Poll a party that cannot call back. The handler (an #[Idempotent]
     * effect) returns null while there is nothing new, or an event (or a list
     * of events, Publish, Send) when there is. `every:` is a duration or a
     * method of the process returning one.
     *
     * @param  class-string  $class
     */
    public function poll(string $class, string|Closure $every, ?string $id = null): self
    {
        Compiler::assertEffect($class);
        $pollId = $id ?? $this->compiler->nextId($this->id().'.poll');
        $this->node['polls'][] = [
            'id' => $pollId,
            'effect' => $class,
            'every' => $every instanceof Closure ? ['method' => $this->compiler->methodName($every, 'interval')] : ['duration' => $every],
        ];

        return $this;
    }

    /**
     * Undo the completed compensable effects (of the whole process, or of the
     * given state) in reverse order on entry, then go to `onDone`.
     */
    public function compensate(string|BackedEnum|null $onDone = null, string|BackedEnum|null $scope = null): self
    {
        $this->node['entry'][] = ['type' => 'compensate', 'scope' => Compiler::target($scope)];
        if ($onDone !== null) {
            $this->node['on'][] = $this->transition('done.compensation', $onDone, null, null, null);
        }

        return $this;
    }

    // Events ------------------------------------------------------------------

    /**
     * Route external events published under a correlation key to this
     * instance while the state is active. `by:` is the context property
     * holding the key.
     *
     * @param  string|list<string>  $events
     */
    public function correlate(string|array $events, string $by): self
    {
        $names = array_map(fn (string $e) => $this->eventName($e), (array) $events);
        $this->node['correlate'][] = ['events' => array_values($names), 'by' => Predicate::path($by)];

        return $this;
    }

    /**
     * Keep these events in the inbox until a state that accepts them is active.
     */
    public function defer(string ...$events): self
    {
        foreach ($events as $event) {
            $this->node['defer'][] = $this->eventName($event);
        }

        return $this;
    }

    // Metadata ----------------------------------------------------------------

    /**
     * Data for interfaces (the engine does not read it): `label` is the
     * status shown to a client, `description` a secondary line.
     *
     * @param  array<string, mixed>  $extra
     */
    public function meta(?string $label = null, ?string $description = null, array $extra = []): self
    {
        $meta = $this->node['meta'] ?? [];
        if ($label !== null) {
            $meta['label'] = $label;
        }
        if ($description !== null) {
            $meta['description'] = $description;
        }
        $this->node['meta'] = array_merge($meta, $extra);

        return $this;
    }

    // Internals ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function transition(?string $event, string|BackedEnum|null $to, Closure|Predicate|null $when, mixed $run, ?string $label): array
    {
        $transition = [];
        if ($event !== null) {
            $transition['event'] = $event;
        }
        if ($when !== null) {
            $transition['guard'] = $this->compiler->guard($when);
        }
        $transition['actions'] = $this->compiler->actions($run, $this->id().'.t');
        if ($to !== null) {
            $transition['target'] = Compiler::id($to);
        }
        if ($label !== null) {
            $transition['label'] = $label;
        }

        return $transition;
    }

    /**
     * @return array<string, mixed>
     */
    private function spawnAction(string $class, ?Closure $input, ?string $key, string $onScopeExit): array
    {
        if (! is_subclass_of($class, Process::class)) {
            throw new DefinitionError("spawn: {$class} must extend ".Process::class);
        }
        $action = [
            'type' => 'spawn',
            'id' => $this->compiler->nextId($this->id().'.spawn.'.Compiler::short($class)),
            'process' => $class,
            'scope' => $this->id(),
            'onScopeExit' => $onScopeExit === 'keep' ? 'keep' : 'cancel',
        ];
        if ($input !== null) {
            $action['input'] = $this->compiler->methodName($input, 'input');
        }
        if ($key !== null) {
            $action['key'] = str_starts_with($key, '$.') ? $key : '$.event.'.$key;
        }

        return $action;
    }

    private function eventName(string $event): string
    {
        if (class_exists($event)) {
            EventName::register($event);
        }

        return Compiler::event($event);
    }

    /**
     * @param  array<string, mixed>  $child
     */
    private function addChild(array $child): int
    {
        if (in_array($this->node['type'] ?? null, ['final', 'choice', 'history'], true)) {
            throw new DefinitionError("State `{$this->id()}` cannot have children");
        }
        $this->node['states'] ??= [];
        $this->node['states'][] = $child;
        if (($this->node['type'] ?? null) === 'atomic') {
            $this->node['type'] = 'compound';
        }

        return count($this->node['states']) - 1;
    }

    private function settleType(): void
    {
        if (($this->node['type'] ?? null) === 'atomic' && ! empty($this->node['states'])) {
            $this->node['type'] = 'compound';
        }
        $this->node['entry'] ??= [];
        if ($this->node['entry'] === []) {
            unset($this->node['entry']);
        }
    }
}
