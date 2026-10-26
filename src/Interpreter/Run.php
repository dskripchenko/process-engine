<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use DateTimeImmutable;
use DateTimeInterface;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Definition\StateNode;
use Dskripchenko\ProcessEngine\Definition\Transition;
use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Support\Duration;
use Dskripchenko\ProcessEngine\Support\Hydrator;

/**
 * The mutable state of one interpretation: kept apart from the Interpreter so
 * the interpreter itself stays stateless and reusable.
 *
 * @internal
 */
final class Run
{
    /** @var array<string, true> */
    public array $active = [];

    /** @var list<Command> */
    public array $commands = [];

    /** @var list<array<string, mixed>> */
    public array $transitions = [];

    /** @var list<array<string, mixed>> */
    public array $rejected = [];

    /** @var list<string> */
    public array $exited = [];

    /** @var list<string> */
    public array $entered = [];

    /** @var list<string> */
    public array $notes = [];

    /** @var list<Event> */
    private array $queue = [];

    public bool $runEntryActions = true;

    public bool $runExitActions = true;

    private int $effectCounter = 0;

    private int $microsteps = 0;

    private readonly Methods $methods;

    public function __construct(
        private readonly Interpreter $interpreter,
        public readonly Definition $definition,
        public Snapshot $snapshot,
        private readonly StepContext $ctx,
    ) {
        foreach ($snapshot->active as $id) {
            $this->active[$id] = true;
        }
        $this->methods = new Methods($definition);
        $this->syncCtx();
    }

    // Results -----------------------------------------------------------------

    /**
     * @param  list<string>  $expected
     */
    public function result(string $outcome, ?string $reason = null, array $expected = []): StepResult
    {
        $this->snapshot->active = $this->orderedActive();

        return new StepResult(
            outcome: $outcome,
            snapshot: $this->snapshot,
            commands: $this->commands,
            transitions: $this->transitions,
            rejected: $this->rejected,
            exited: $this->exited,
            entered: $this->entered,
            notes: $this->notes,
            expected: $expected,
            reason: $reason,
        );
    }

    // Selection ---------------------------------------------------------------

    public function staleReason(Event $event): ?string
    {
        $name = $event->name;
        $owner = null;
        foreach (['done.invoke.', 'item.invoke.', 'error.invoke.', 'unknown.invoke.'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $owner = $this->definition->invokeOwner(substr($name, strlen($prefix)));
                if ($owner === null) {
                    return 'unknown invocation';
                }
            }
        }
        if ($owner === null && str_starts_with($name, 'after.')) {
            $owner = $this->definition->timerOwner(substr($name, strlen('after.')));
            if ($owner === null) {
                return 'unknown timer';
            }
        }
        if ($owner === null) {
            return null;
        }
        $activation = $event->payload['activation'] ?? null;
        if (! $this->isActive($owner) || $activation !== $this->snapshot->activation($owner->id)) {
            return "late result for {$activation}: the state is no longer in that activation";
        }

        return null;
    }

    /**
     * Records one element of a forEach invocation; returns the completed
     * `done.invoke` event when all elements are in.
     */
    public function collect(Event $event): ?Event
    {
        $invokeId = substr($event->name, strlen('item.invoke.'));
        $owner = $this->definition->invokeOwner($invokeId);
        if ($owner === null) {
            return null;
        }
        $key = $this->activationKey($owner).'/'.$invokeId;
        $entry = $this->snapshot->invocations[$key] ?? ['total' => 0, 'outputs' => []];
        $index = (int) ($event->payload['index'] ?? 0);
        $entry['outputs'][(string) $index] = $event->payload['output'] ?? null;
        if (count($entry['outputs']) < (int) $entry['total']) {
            $this->snapshot->invocations[$key] = $entry;

            return null;
        }
        unset($this->snapshot->invocations[$key]);
        $outputs = $entry['outputs'];
        ksort($outputs, SORT_NUMERIC);

        return new Event('done.invoke.'.$invokeId, [
            'activation' => $event->payload['activation'] ?? null,
            'output' => array_values($outputs),
        ]);
    }

    /**
     * @return list<Transition>
     */
    public function select(?Event $event): array
    {
        $enabled = [];
        foreach ($this->activeAtomic() as $state) {
            foreach ([$state, ...$state->ancestors()] as $node) {
                $found = null;
                foreach ($event === null ? $node->always : $node->on as $transition) {
                    if ($event !== null && ! $transition->matches($event->name)) {
                        continue;
                    }
                    if ($this->guard($transition, $event)) {
                        $found = $transition;
                        break;
                    }
                    if ($event !== null) {
                        $this->rejected[] = ['source' => $node->id, 'target' => $transition->target, 'event' => $event->name, 'guard' => self::describeGuard($transition->guard)];
                    }
                }
                if ($found !== null) {
                    if (! in_array($found, $enabled, true)) {
                        $enabled[] = $found;
                    }
                    break;
                }
            }
        }

        return $this->removeConflicts($enabled);
    }

    public function defers(string $event): bool
    {
        foreach ($this->activeNodes() as $node) {
            if (in_array($event, $node->defer, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function expectedNames(): array
    {
        return array_values(array_unique(array_map(fn (ExpectedEvent $e) => $e->event, $this->expected())));
    }

    /**
     * @return list<ExpectedEvent>
     */
    public function expected(): array
    {
        $out = [];
        $seen = [];
        foreach ($this->activeAtomic() as $state) {
            foreach ([$state, ...$state->ancestors()] as $node) {
                foreach ($node->on as $t) {
                    if ($t->event === null || (new Event($t->event))->isInternal()) {
                        continue;
                    }
                    $conditional = $this->guardReadsEvent($t->guard);
                    if (! $conditional && $t->guard !== null && ! $this->guard($t, null)) {
                        continue;
                    }
                    $key = $t->event.'→'.($t->target ?? '');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = new ExpectedEvent($t->event, $t->label, $t->target, $node->id, $conditional);
                }
                foreach ($node->timers as $timer) {
                    $out[] = new ExpectedEvent('after.'.$timer['id'], null, null, $node->id, false, 'timer');
                }
            }
        }

        return $out;
    }

    // Microsteps --------------------------------------------------------------

    /**
     * @param  list<Transition>  $transitions
     */
    public function microstep(array $transitions, ?Event $event): void
    {
        if (++$this->microsteps > $this->interpreter->maxMicrosteps()) {
            throw new MicrostepLimitExceeded("More than {$this->interpreter->maxMicrosteps()} microsteps in one step: an eventless loop?");
        }

        $this->exitStates($transitions);
        foreach ($transitions as $transition) {
            $this->transitions[] = [
                'source' => $transition->source->id,
                'target' => $transition->target,
                'event' => $event?->name,
                'guard' => self::describeGuard($transition->guard),
            ];
            $this->runActions($transition->actions, $event, 't');
        }
        $this->enterStates($transitions);
    }

    /**
     * Eventless transitions and internal events until nothing changes.
     */
    public function settle(): void
    {
        while ($this->snapshot->status === Snapshot::RUNNING) {
            $eventless = $this->select(null);
            if ($eventless !== []) {
                $this->microstep($eventless, null);

                continue;
            }
            if ($this->queue === []) {
                break;
            }
            $event = array_shift($this->queue);
            $enabled = $this->select($event);
            if ($enabled !== []) {
                $this->microstep($enabled, $event);
            }
        }
    }

    public function enterRoot(bool $enterInitial = true): void
    {
        $root = $this->definition->root;
        $this->snapshot->activations[$root->id] = ($this->snapshot->activations[$root->id] ?? 0) + 1;
        $this->startActivities($root);
        if ($enterInitial) {
            $toEnter = [];
            $forDefault = [];
            $this->addDescendants($this->initialChild($root), $toEnter, $forDefault);
            $this->enterSet($toEnter);
        }
    }

    public function exitRoot(): void
    {
        $root = $this->definition->root;
        $this->commands[] = new Command(Command::CANCEL_ACTIVATION, ['prefix' => $this->activationKey($root).'/', 'state' => $root->id]);
    }

    /**
     * @param  list<string>  $targets
     */
    public function enterTargets(array $targets, StateNode $domain): void
    {
        $toEnter = [];
        $forDefault = [];
        foreach ($targets as $target) {
            foreach ($this->effectiveTargets($this->definition->node($target)) as $node) {
                $this->addDescendants($node, $toEnter, $forDefault);
                $this->addAncestors($node, $domain, $toEnter, $forDefault);
            }
        }
        $this->enterSet($toEnter);
    }

    public function exitAll(): void
    {
        $nodes = $this->activeNodes();
        usort($nodes, fn (StateNode $a, StateNode $b) => $b->order <=> $a->order);
        foreach ($nodes as $node) {
            $this->exitState($node);
        }
    }

    /**
     * @param  list<Transition>  $transitions
     */
    private function exitStates(array $transitions): void
    {
        $exit = [];
        foreach ($transitions as $transition) {
            foreach ($this->exitSet($transition) as $node) {
                $exit[$node->id] = $node;
            }
        }
        usort($exit, fn (StateNode $a, StateNode $b) => $b->order <=> $a->order);

        foreach ($exit as $node) {
            foreach ($node->children as $child) {
                if (! $child->isHistory()) {
                    continue;
                }
                $remember = $child->history === 'deep'
                    ? array_map(fn (StateNode $n) => $n->id, array_filter($this->activeAtomic(), fn (StateNode $n) => $n->isDescendantOf($node)))
                    : array_map(fn (StateNode $n) => $n->id, array_filter($node->children, fn (StateNode $n) => $this->isActive($n)));
                $this->snapshot->history[$child->id] = array_values($remember);
            }
        }
        foreach ($exit as $node) {
            $this->exitState($node);
        }
    }

    private function exitState(StateNode $node): void
    {
        if (! $this->isActive($node)) {
            return;
        }
        if ($this->runExitActions) {
            $this->runActions($node->exit, null, $node->id.'.exit');
        }
        $this->commands[] = new Command(Command::CANCEL_ACTIVATION, ['prefix' => $this->activationKey($node).'/', 'state' => $node->id]);
        foreach (array_keys($this->snapshot->invocations) as $key) {
            if (str_starts_with($key, $this->activationKey($node).'/')) {
                unset($this->snapshot->invocations[$key]);
            }
        }
        unset($this->active[$node->id], $this->snapshot->entered[$node->id]);
        $this->exited[] = $node->id;
        $this->syncCtx();
    }

    /**
     * @param  list<Transition>  $transitions
     */
    private function enterStates(array $transitions): void
    {
        $toEnter = [];
        $forDefault = [];
        foreach ($transitions as $transition) {
            if ($transition->target === null) {
                continue;
            }
            $domain = $this->domain($transition);
            foreach ($this->effectiveTargets($this->definition->node($transition->target)) as $node) {
                $this->addDescendants($node, $toEnter, $forDefault);
                $this->addAncestors($node, $domain, $toEnter, $forDefault);
            }
        }
        $this->enterSet($toEnter);
    }

    /**
     * @param  array<string, StateNode>  $toEnter
     */
    private function enterSet(array $toEnter): void
    {
        $nodes = array_values($toEnter);
        usort($nodes, fn (StateNode $a, StateNode $b) => $a->order <=> $b->order);

        foreach ($nodes as $node) {
            if ($this->isActive($node) || $node->isHistory()) {
                continue;
            }
            $this->active[$node->id] = true;
            $this->snapshot->activations[$node->id] = ($this->snapshot->activations[$node->id] ?? 0) + 1;
            $this->snapshot->entered[$node->id] = $this->ctx->now()->format(DateTimeInterface::RFC3339_EXTENDED);
            $this->entered[] = $node->id;
            $this->syncCtx();
            if ($this->runEntryActions) {
                $this->runActions($node->entry, null, $node->id.'.entry');
            }
            $this->startActivities($node);

            if ($node->isFinal()) {
                $this->finalEntered($node);
                if ($this->snapshot->status !== Snapshot::RUNNING) {
                    return;
                }
            }
        }
    }

    private function finalEntered(StateNode $node): void
    {
        $parent = $node->parent;
        if ($parent === null) {
            return;
        }
        $output = $this->output($node);
        if ($parent === $this->definition->root) {
            $this->snapshot->status = Snapshot::COMPLETED;
            $this->snapshot->final = $node->id;
            $this->snapshot->output = $output;
            $this->exitRoot();
            $this->commands[] = new Command(Command::COMPLETE, ['final' => $node->id, 'output' => $output]);

            return;
        }
        $this->queue[] = new Event('done.state.'.$parent->id, ['output' => $output, 'final' => $node->id]);
        $grand = $parent->parent;
        if ($grand !== null && $grand->isParallel()) {
            if ($grand->join === 'any') {
                $this->queue[] = new Event('done.state.'.$grand->id, ['output' => [$parent->id => $output], 'region' => $parent->id]);
            } elseif (array_filter($grand->stateChildren(), fn (StateNode $r) => ! $this->inFinal($r)) === []) {
                $outputs = [];
                foreach ($grand->stateChildren() as $region) {
                    $outputs[$region->id] = $this->regionOutput($region);
                }
                $this->queue[] = new Event('done.state.'.$grand->id, ['output' => $outputs]);
            }
        }
    }

    // Entry set computation ---------------------------------------------------

    /**
     * @param  array<string, StateNode>  $toEnter
     * @param  array<string, StateNode>  $forDefault
     */
    private function addDescendants(StateNode $node, array &$toEnter, array &$forDefault): void
    {
        if ($node->isHistory()) {
            foreach ($this->effectiveTargets($node) as $target) {
                $this->addDescendants($target, $toEnter, $forDefault);
                $this->addAncestors($target, $node->parent ?? $this->definition->root, $toEnter, $forDefault);
            }

            return;
        }
        $toEnter[$node->id] = $node;
        if ($node->isCompound()) {
            $forDefault[$node->id] = $node;
            $initial = $this->initialChild($node);
            $this->addDescendants($initial, $toEnter, $forDefault);
        } elseif ($node->isParallel()) {
            foreach ($node->stateChildren() as $region) {
                if (! $this->hasDescendantIn($region, $toEnter)) {
                    $this->addDescendants($region, $toEnter, $forDefault);
                }
            }
        }
    }

    /**
     * @param  array<string, StateNode>  $toEnter
     * @param  array<string, StateNode>  $forDefault
     */
    private function addAncestors(StateNode $node, StateNode $upTo, array &$toEnter, array &$forDefault): void
    {
        foreach ($node->ancestors($upTo) as $ancestor) {
            if ($ancestor === $this->definition->root) {
                continue;
            }
            $toEnter[$ancestor->id] = $ancestor;
            if ($ancestor->isParallel()) {
                foreach ($ancestor->stateChildren() as $region) {
                    if (! $this->hasDescendantIn($region, $toEnter)) {
                        $this->addDescendants($region, $toEnter, $forDefault);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, StateNode>  $set
     */
    private function hasDescendantIn(StateNode $node, array $set): bool
    {
        foreach ($set as $candidate) {
            if ($candidate === $node || $candidate->isDescendantOf($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<StateNode>
     */
    private function effectiveTargets(StateNode $node): array
    {
        if (! $node->isHistory()) {
            return [$node];
        }
        $remembered = $this->snapshot->history[$node->id] ?? null;
        if ($remembered !== null && $remembered !== []) {
            return array_map(fn (string $id) => $this->definition->node($id), $remembered);
        }
        $parent = $node->parent ?? $this->definition->root;
        $default = $node->historyDefault !== null ? $this->definition->node($node->historyDefault) : $this->initialChild($parent);

        return [$default];
    }

    private function initialChild(StateNode $node): StateNode
    {
        if ($node->initial !== null) {
            return $this->definition->node($node->initial);
        }
        $children = $node->stateChildren();
        if ($children === []) {
            throw new DefinitionError("State `{$node->id}` has no child states to enter");
        }

        return $children[0];
    }

    // Domains and conflicts ---------------------------------------------------

    /**
     * @return list<StateNode>
     */
    private function exitSet(Transition $transition): array
    {
        if ($transition->target === null) {
            return [];
        }
        $domain = $this->domain($transition);
        $out = [];
        foreach ($this->activeNodes() as $node) {
            if ($node->isDescendantOf($domain)) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function domain(Transition $transition): StateNode
    {
        $targets = $transition->target !== null ? [$this->definition->node($transition->target)] : [];
        if ($targets === []) {
            return $transition->source;
        }

        return $this->lcca([$transition->source, ...$targets]);
    }

    /**
     * The least common compound ancestor: the nearest compound (or root)
     * proper ancestor of the first node that contains all the others.
     *
     * @param  list<StateNode>  $nodes
     */
    private function lcca(array $nodes): StateNode
    {
        $first = array_shift($nodes);
        if ($first === null) {
            return $this->definition->root;
        }
        foreach ($first->ancestors() as $ancestor) {
            if ($ancestor->isParallel()) {
                continue;
            }
            $all = true;
            foreach ($nodes as $node) {
                if (! $node->isDescendantOf($ancestor)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return $ancestor;
            }
        }

        return $this->definition->root;
    }

    /**
     * @param  list<Transition>  $enabled
     * @return list<Transition>
     */
    private function removeConflicts(array $enabled): array
    {
        $filtered = [];
        foreach ($enabled as $t1) {
            $preempted = false;
            $toRemove = [];
            foreach ($filtered as $i => $t2) {
                $exit1 = array_map(fn (StateNode $n) => $n->id, $this->exitSet($t1));
                $exit2 = array_map(fn (StateNode $n) => $n->id, $this->exitSet($t2));
                if (array_intersect($exit1, $exit2) === []) {
                    continue;
                }
                if ($t1->source->isDescendantOf($t2->source)) {
                    $toRemove[] = $i;
                } else {
                    $preempted = true;
                    break;
                }
            }
            if (! $preempted) {
                foreach ($toRemove as $i) {
                    unset($filtered[$i]);
                }
                $filtered[] = $t1;
                $filtered = array_values($filtered);
            }
        }

        return $filtered;
    }

    // Activities --------------------------------------------------------------

    private function startActivities(StateNode $node): void
    {
        $key = $this->activationKey($node);
        $activation = $this->snapshot->activation($node->id);

        foreach ($node->timers as $timer) {
            $spec = isset($timer['delay']) ? (array) $timer['delay'] : (array) ($timer['at'] ?? []);
            $at = $this->moment($spec, isset($timer['delay']));
            if ($at === null) {
                $this->notes[] = "Timer {$timer['id']} not set: no moment";

                continue;
            }
            $this->commands[] = new Command(Command::START_TIMER, [
                'key' => $key.'/'.$timer['id'],
                'timer' => $timer['id'],
                'event' => 'after.'.$timer['id'],
                'fireAt' => $at->format(DateTimeInterface::RFC3339_EXTENDED),
                'activation' => $activation,
            ]);
        }

        foreach ($node->correlate as $i => $correlation) {
            $value = Expression::read($correlation['by'], $this->scope(null));
            if ($value === null || $value === '') {
                $this->notes[] = "No correlation key at {$correlation['by']} for ".implode(', ', $correlation['events']);

                continue;
            }
            $this->commands[] = new Command(Command::SUBSCRIBE, [
                'key' => $key.'/correlate'.$i,
                'events' => $correlation['events'],
                'correlationKey' => is_scalar($value) ? (string) $value : json_encode($value),
                'activation' => $activation,
            ]);
        }

        foreach ($node->polls as $poll) {
            $next = $this->moment((array) ($poll['every'] ?? []), true) ?? $this->ctx->now()->modify('+1 minute');
            $this->commands[] = new Command(Command::START_POLL, [
                'key' => $key.'/'.$poll['id'],
                'poll' => $poll['id'],
                'class' => $poll['effect'],
                'nextAt' => $next->format(DateTimeInterface::RFC3339_EXTENDED),
                'activation' => $activation,
            ]);
        }

        foreach ($node->invoke as $invoke) {
            $this->startInvoke($node, $invoke, $key, $activation);
        }
    }

    /**
     * @param  array<string, mixed>  $invoke
     */
    private function startInvoke(StateNode $node, array $invoke, string $key, string $activation): void
    {
        $invokeId = (string) $invoke['id'];
        $invokeKey = $key.'/'.$invokeId;

        if (isset($invoke['forEach'])) {
            $items = Expression::read((string) $invoke['forEach'], $this->scope(null));
            $items = is_array($items) ? array_values($items) : [];
            if ($items === []) {
                $this->queue[] = new Event('done.invoke.'.$invokeId, ['activation' => $activation, 'output' => []]);

                return;
            }
            $this->snapshot->invocations[$invokeKey] = ['total' => count($items), 'outputs' => []];
            foreach ($items as $index => $item) {
                $this->dispatchInvoke($invoke, $invokeKey.'#'.$index, $activation, $item, $index, $node);
            }

            return;
        }

        $this->dispatchInvoke($invoke, $invokeKey, $activation, null, null, $node);
    }

    /**
     * @param  array<string, mixed>  $invoke
     */
    private function dispatchInvoke(array $invoke, string $key, string $activation, mixed $item, ?int $index, StateNode $node): void
    {
        $input = isset($invoke['input'])
            ? $this->call((string) $invoke['input'], null, $item !== null ? [$item] : [])
            : null;

        if (isset($invoke['process'])) {
            $childInput = $input ?? ($item !== null ? $item : []);
            $this->commands[] = new Command(Command::START_CHILD, [
                'key' => $key,
                'process' => $invoke['process'],
                'input' => Hydrator::dehydrate($childInput),
                'inputClass' => is_object($childInput) ? $childInput::class : null,
                'invoke' => $invoke['id'],
                'activation' => $activation,
                'item' => $index !== null,
                'index' => $index,
                'onScopeExit' => 'cancel',
            ]);

            return;
        }

        $this->commands[] = new Command(Command::SCHEDULE_EFFECT, [
            'key' => $key,
            'class' => $invoke['effect'],
            'kind' => 'invoke',
            'context' => $this->snapshot->context,
            'contextClass' => $this->snapshot->contextClass,
            'input' => Hydrator::dehydrate($input),
            'item' => Hydrator::dehydrate($item),
            'index' => $index,
            'invoke' => $invoke['id'],
            'activation' => $activation,
            'retry' => $invoke['retry'] ?? null,
            'concurrency' => $invoke['concurrency'] ?? null,
            'state' => $node->id,
            'scopes' => $this->scopes(),
        ]);
    }

    // Actions -----------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function runActions(array $actions, ?Event $event, string $owner): void
    {
        foreach ($actions as $action) {
            switch ($action['type'] ?? null) {
                case 'method':
                    $this->call((string) $action['name'], $event);
                    break;
                case 'assign':
                    $this->assign((array) ($action['set'] ?? []), $event);
                    break;
                case 'raise':
                    $this->queue[] = new Event((string) $action['event'], (array) ($action['payload'] ?? []));
                    break;
                case 'log':
                    $this->notes[] = (string) ($action['message'] ?? '');
                    break;
                case 'effect':
                    $this->commands[] = new Command(Command::SCHEDULE_EFFECT, [
                        'key' => $this->ctx->instanceId.'/step'.$this->ctx->seq.'/'.($action['id'] ?? $owner).'#'.$this->effectCounter++,
                        'class' => $action['class'],
                        'kind' => 'action',
                        'context' => $this->snapshot->context,
                        'contextClass' => $this->snapshot->contextClass,
                        'input' => $event?->payload,
                        'retry' => $action['retry'] ?? null,
                        'scopes' => $this->scopes(),
                    ]);
                    break;
                case 'spawn':
                    $this->spawn($action, $event);
                    break;
                case 'compensate':
                    $this->commands[] = new Command(Command::COMPENSATE, ['scope' => $action['scope'] ?? null]);
                    break;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $set
     */
    private function assign(array $set, ?Event $event): void
    {
        $scope = $this->scope($event);
        $context = $this->snapshot->context;
        foreach ($set as $path => $spec) {
            $spec = (array) $spec;
            if (array_key_exists('value', $spec)) {
                $value = $spec['value'];
            } elseif (isset($spec['path'])) {
                $value = Expression::read((string) $spec['path'], $scope);
            } elseif (isset($spec['inc'])) {
                $value = (Expression::read((string) $path, ['context' => $context]) ?? 0) + $spec['inc'];
            } else {
                continue;
            }
            $context = Expression::write($context, (string) $path, Hydrator::dehydrate($value));
        }
        $this->snapshot->context = $context;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function spawn(array $action, ?Event $event): void
    {
        $scopeId = (string) ($action['scope'] ?? Definition::ROOT);
        $scopeNode = $this->definition->has($scopeId) ? $this->definition->node($scopeId) : $this->definition->root;
        $keyValue = isset($action['key']) ? Expression::read((string) $action['key'], $this->scope($event)) : null;
        $suffix = $keyValue !== null && is_scalar($keyValue) ? (string) $keyValue : 'step'.$this->ctx->seq.'-'.$this->effectCounter++;
        $input = isset($action['input']) ? $this->call((string) $action['input'], $event) : (($event !== null ? $event->payload : []));

        $this->commands[] = new Command(Command::START_CHILD, [
            'key' => $this->activationKey($scopeNode).'/spawn/'.$action['id'].'/'.$suffix,
            'process' => $action['process'],
            'input' => Hydrator::dehydrate($input),
            'inputClass' => is_object($input) ? $input::class : null,
            'spawn' => $action['id'],
            'activation' => $this->snapshot->activation($scopeNode->id),
            'onScopeExit' => $action['onScopeExit'] ?? 'cancel',
        ]);
    }

    private function guard(Transition $transition, ?Event $event): bool
    {
        $guard = $transition->guard;
        if ($guard === null) {
            return true;
        }
        if (($guard['type'] ?? null) === 'predicate') {
            return Expression::test((array) ($guard['expr'] ?? []), $this->scope($event), $this->interpreter->flags());
        }

        return (bool) $this->call((string) ($guard['name'] ?? ''), $event);
    }

    /**
     * @param  array<string, mixed>|null  $guard
     */
    private function guardReadsEvent(?array $guard): bool
    {
        if ($guard === null) {
            return false;
        }
        if (($guard['type'] ?? null) === 'predicate') {
            return Expression::readsEvent((array) ($guard['expr'] ?? []));
        }

        return $this->methods->readsEvent((string) ($guard['name'] ?? ''), $this->snapshot->contextClass);
    }

    /**
     * @param  list<mixed>  $extras
     */
    private function call(string $method, ?Event $event, array $extras = []): mixed
    {
        $context = $this->snapshot->context;
        $result = $this->methods->call($method, $context, $this->snapshot->contextClass, $event, $this->ctx, $extras);
        $this->snapshot->context = $context;

        return $result;
    }

    // Helpers -----------------------------------------------------------------

    /**
     * A moment from a time spec: `{duration}`, `{method}`, `{path}`, `{businessDays}`.
     *
     * @param  array<string, mixed>  $spec
     */
    public function moment(array $spec, bool $relative): ?DateTimeImmutable
    {
        $now = $this->ctx->now();
        if (isset($spec['duration'])) {
            return Duration::add($now, (string) $spec['duration']);
        }
        if (isset($spec['businessDays'])) {
            return $this->interpreter->calendar()->addBusinessDays($now, (int) $spec['businessDays']);
        }
        $value = null;
        if (isset($spec['method'])) {
            $value = $this->call((string) $spec['method'], null);
        } elseif (isset($spec['path'])) {
            $value = Expression::read((string) $spec['path'], $this->scope(null));
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if ($value instanceof \DateInterval) {
            return $now->add($value);
        }
        if (is_string($value) && $value !== '') {
            if ($relative && Duration::isValid($value)) {
                return Duration::add($now, $value);
            }
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                return Duration::isValid($value) ? Duration::add($now, $value) : null;
            }
        }

        return null;
    }

    private function output(StateNode $final): mixed
    {
        $spec = $final->output;
        if ($spec === null) {
            return null;
        }
        if (isset($spec['path'])) {
            return Expression::read((string) $spec['path'], $this->scope(null));
        }
        if (isset($spec['method'])) {
            return Hydrator::dehydrate($this->call((string) $spec['method'], null));
        }

        return null;
    }

    private function regionOutput(StateNode $region): mixed
    {
        foreach ($region->children as $child) {
            if ($child->isFinal() && $this->isActive($child)) {
                return $this->output($child);
            }
        }

        return null;
    }

    private function inFinal(StateNode $node): bool
    {
        if ($node->isCompound()) {
            foreach ($node->children as $child) {
                if ($child->isFinal() && $this->isActive($child)) {
                    return true;
                }
            }

            return false;
        }
        if ($node->isParallel()) {
            return array_filter($node->stateChildren(), fn (StateNode $r) => ! $this->inFinal($r)) === [];
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(?Event $event): array
    {
        return ['context' => $this->snapshot->context, 'event' => ($event !== null ? $event->payload : [])];
    }

    public function activationKey(StateNode $node): string
    {
        return $this->ctx->instanceId.'/'.$this->snapshot->activation($node->id);
    }

    public function isActive(StateNode $node): bool
    {
        return $node === $this->definition->root || isset($this->active[$node->id]);
    }

    /**
     * @return list<StateNode>
     */
    private function activeNodes(): array
    {
        $nodes = [];
        foreach (array_keys($this->active) as $id) {
            if ($this->definition->has($id)) {
                $nodes[] = $this->definition->node($id);
            }
        }
        usort($nodes, fn (StateNode $a, StateNode $b) => $a->order <=> $b->order);

        return $nodes;
    }

    /**
     * @return list<StateNode>
     */
    private function activeAtomic(): array
    {
        return array_values(array_filter($this->activeNodes(), fn (StateNode $n) => $n->isAtomic()));
    }

    /**
     * @return list<string>
     */
    private function orderedActive(): array
    {
        return array_map(fn (StateNode $n) => $n->id, $this->activeNodes());
    }

    /**
     * Active states from the root down: compensation scopes of an effect.
     *
     * @return list<string>
     */
    private function scopes(): array
    {
        return [Definition::ROOT, ...$this->orderedActive()];
    }

    private function syncCtx(): void
    {
        $this->ctx->active = array_keys($this->active);
    }

    /**
     * @param  array<string, mixed>|null  $guard
     */
    public static function describeGuard(?array $guard): ?string
    {
        if ($guard === null) {
            return null;
        }
        if (($guard['type'] ?? null) === 'method') {
            return (string) ($guard['name'] ?? '');
        }

        return json_encode($guard['expr'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
