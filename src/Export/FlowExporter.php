<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Export;

use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\StateNode;
use Dskripchenko\ProcessEngine\Definition\Transition;
use Dskripchenko\ProcessEngine\Interpreter\Run;
use Dskripchenko\ProcessEngine\Interpreter\SwitchProvider;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Query\InstanceView;
use Dskripchenko\ProcessEngine\Store\BatchRecord;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;

/**
 * Exports definitions and instances in the format @dskripchenko/flow draws
 * without adapters: Graph v1 (`graph()`) and MonitorSnapshot (`snapshot()`,
 * `overview()`), one level at a time for drill-down.
 *
 * A level is the root or a compound/parallel state; its members are its
 * child states (for a parallel state, the states of its regions, grouped).
 * Compound and parallel members become `subprocess` nodes with an inline
 * subgraph; a state invoking a child process becomes a `subprocess` with a
 * string reference `name@version`.
 */
final class FlowExporter
{
    /**
     * @param  SwitchProvider|null  $switches  to show switchable states on or off (ADR-015)
     */
    public function __construct(private readonly ?SwitchProvider $switches = null) {}

    public const START = '__start';

    /**
     * @param  list<string>  $path  ids of the compound states drilled into (empty: the root)
     * @return array<string, mixed>
     */
    public function graph(Definition $definition, array $path = []): array
    {
        $level = $this->level($definition, $path);
        $graph = $this->levelGraph($definition, $level);
        if ($path === []) {
            $graph['meta'] = ['title' => $definition->name(), 'direction' => 'LR', 'version' => $definition->version()];
        }

        return $graph;
    }

    /**
     * The monitoring snapshot of one instance at a level.
     *
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    public function snapshot(InstanceView $instance, array $path = []): array
    {
        $definition = $instance->definition();
        $level = $this->level($definition, $path);
        $members = $this->members($level);
        $snapshot = $instance->snapshot();
        $active = array_flip($snapshot->active);

        $entered = [];
        $sequence = [];
        $traversed = [];
        foreach ($instance->journal() as $entry) {
            foreach ((array) ($entry->data['entered'] ?? []) as $state) {
                $member = $this->memberOf($definition, $members, (string) $state);
                if ($member === null) {
                    continue;
                }
                if ($state === $member->id) {
                    $entered[$member->id] = ($entered[$member->id] ?? 0) + 1;
                }
                if (end($sequence) !== $member->id) {
                    $sequence[] = $member->id;
                }
            }
            foreach ((array) ($entry->data['transitions'] ?? []) as $t) {
                if (! is_array($t) || ! isset($t['source'], $t['target'])) {
                    continue;
                }
                $from = $this->memberOf($definition, $members, (string) $t['source']);
                $to = $this->memberOf($definition, $members, (string) $t['target']);
                if ($from !== null && $to !== null) {
                    $key = $from->id.'→'.$to->id;
                    $traversed[$key] = ($traversed[$key] ?? 0) + 1;
                }
            }
        }

        $effects = $instance->effects();
        $incidents = $instance->incidents(IncidentRecord::OPEN);
        $batches = [];
        foreach ($instance->batches() as $batch) {
            $member = $this->memberOf($definition, $members, $batch->state);
            if ($member !== null && $batch->isRunning()) {
                $batches[$member->id] = $batch;
            }
        }
        $nodes = [];
        foreach ($members as $member) {
            $run = [];
            $problemEffects = array_filter($effects, fn (EffectRecord $e) => $this->effectIn($definition, $e, $member));
            $unknown = array_filter($problemEffects, fn (EffectRecord $e) => in_array($e->status, [EffectRecord::UNKNOWN, EffectRecord::RECONCILING], true));
            $effectIds = array_map(fn (EffectRecord $e) => $e->id, $problemEffects);
            $memberIncidents = array_filter($incidents, fn (IncidentRecord $i) => $i->effectId !== null && in_array($i->effectId, $effectIds, true));

            if ($memberIncidents !== []) {
                $run['status'] = 'error';
                $run['badge'] = count($memberIncidents);
                $run['message'] = array_values($memberIncidents)[0]->message;
            } elseif ($unknown !== [] && isset($active[$member->id])) {
                $run['status'] = 'unknown';
                $run['message'] = 'Outcome unknown: '.self::short(array_values($unknown)[0]->class);
            } elseif (isset($active[$member->id])) {
                $running = array_filter($problemEffects, fn (EffectRecord $e) => in_array($e->status, [EffectRecord::PENDING, EffectRecord::RUNNING, EffectRecord::WAITING], true) && $e->kind !== EffectRecord::KIND_POLL);
                $run['status'] = ($member->isAtomic() && $running === []) ? 'waiting' : 'active';
                $waitingFor = $this->waitingFor($instance, $member);
                if ($run['status'] === 'waiting' && $waitingFor !== '') {
                    $run['message'] = $waitingFor;
                }
                if (isset($snapshot->entered[$member->id])) {
                    $run['updatedAt'] = $snapshot->entered[$member->id];
                }
                if (isset($batches[$member->id])) {
                    $run['status'] = 'active';
                    [$run['badge'], $run['message']] = self::progress($batches[$member->id]);
                }
            } elseif ($member->isFinal() && $instance->final() === $member->id) {
                $run['status'] = 'done';
            } elseif (isset($entered[$member->id])) {
                $run['status'] = 'done';
            }
            if (($entered[$member->id] ?? 0) > 1) {
                $run['count'] = $entered[$member->id];
            }
            if ($run !== []) {
                $nodes[$member->id] = $run;
            }
        }

        $edges = [];
        foreach ($this->edges($definition, $level) as $edge) {
            $key = $edge['from'].'→'.$edge['to'];
            if (isset($traversed[$key])) {
                $edges[$edge['id']] = ['traversed' => true] + ($traversed[$key] > 1 ? ['count' => $traversed[$key]] : []);
            }
        }

        $out = [
            'nodes' => (object) $nodes,
            'edges' => (object) $edges,
            'path' => $sequence,
            'current' => array_values(array_map(fn (StateNode $m) => $m->id, array_filter($members, fn (StateNode $m) => isset($active[$m->id])))),
        ];
        if ($instance->record()->updatedAt !== null) {
            $out['updatedAt'] = $instance->record()->updatedAt->format(DATE_ATOM);
        }

        return $out;
    }

    /**
     * The progress of a running batch: a badge (finished / total) and a line of counters.
     *
     * @return array{0: string, 1: string}
     */
    private static function progress(BatchRecord $batch): array
    {
        $s = $batch->summary();
        $finished = $s['done'] + $s['not_found'] + $s['failed'] + $s['deferred'] + $s['skipped'];
        $total = $batch->total ?? ($batch->exhausted ? $s['total'] : null);
        $parts = ["done {$s['done']}"];
        foreach (['not_found' => 'not found', 'failed' => 'failed', 'deferred' => 'deferred', 'skipped' => 'skipped'] as $key => $label) {
            if ($s[$key] > 0) {
                $parts[] = "{$label} {$s[$key]}";
            }
        }

        return [$total !== null ? "{$finished} / {$total}" : (string) $finished, implode(', ', $parts)];
    }

    /**
     * Aggregate counters of a version: instances per state, at a level.
     *
     * @param  array<string, int>  $counts  state id → instances (Engine::stateCounts)
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    public function overview(Definition $definition, array $counts, array $path = []): array
    {
        $level = $this->level($definition, $path);
        $nodes = [];
        foreach ($this->members($level) as $member) {
            if (($counts[$member->id] ?? 0) > 0) {
                $nodes[$member->id] = ['count' => $counts[$member->id], 'status' => 'active'];
            }
        }

        return ['nodes' => (object) $nodes, 'edges' => (object) []];
    }

    // Graph -------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function levelGraph(Definition $definition, StateNode $level): array
    {
        $nodes = [];
        $groups = [];
        $starts = $level->isParallel() ? $level->stateChildren() : [$level];
        foreach ($starts as $region) {
            if ($region->stateChildren() === []) {
                continue;
            }
            $start = ['id' => $region->id.'.'.self::START, 'type' => 'start'];
            if ($level->isParallel()) {
                $start['group'] = $region->id;
                $groups[] = ['id' => $region->id, 'label' => $region->label() ?? $region->id];
            }
            $nodes[] = $start;
        }
        foreach ($this->members($level) as $member) {
            $nodes[] = $this->node($definition, $member, $level);
        }
        foreach ($this->spawns($level) as $spawn) {
            $nodes[] = $spawn['node'];
        }

        $edges = [];
        foreach ($starts as $region) {
            $initial = $region->initial !== null ? $definition->node($region->initial) : ($region->stateChildren()[0] ?? null);
            if ($initial !== null) {
                $edges[] = ['id' => 'edge:'.$region->id.'.'.self::START, 'from' => $region->id.'.'.self::START, 'to' => $initial->id];
            }
        }
        foreach ($this->edges($definition, $level) as $edge) {
            $edges[] = $edge;
        }
        // Transitions declared on the level itself apply from any of its states.
        if (! $level->isParallel() && $level->stateChildren() !== []) {
            $members = $this->members($level);
            foreach ($level->on as $transition) {
                $to = $transition->target !== null ? $this->memberOf($definition, $members, $transition->target) : null;
                if ($to === null) {
                    continue;
                }
                $edge = ['id' => 'edge:'.$transition->order, 'from' => $level->id.'.'.self::START, 'to' => $to->id, 'label' => ($this->label($definition, $transition) ?? '').' (any state)'];
                $condition = $this->condition($transition);
                if ($condition !== null) {
                    $edge['condition'] = $condition;
                }
                $edges[] = $edge;
            }
        }
        foreach ($this->spawns($level) as $spawn) {
            $edges[] = $spawn['edge'];
        }

        $graph = ['version' => 1, 'nodes' => $nodes, 'edges' => $edges];
        if ($groups !== []) {
            $graph['groups'] = $groups;
        }

        return $graph;
    }

    /**
     * @return array<string, mixed>
     */
    private function node(Definition $definition, StateNode $member, StateNode $level): array
    {
        $node = ['id' => $member->id, 'label' => $member->label() ?? $member->id];
        if (isset($member->meta['description']) && is_string($member->meta['description'])) {
            $node['description'] = $member->meta['description'];
        }
        if ($level->isParallel() && $member->parent !== null) {
            $node['group'] = $member->parent->id;
        }
        $data = array_diff_key($member->meta, ['label' => true, 'description' => true]);

        $processInvoke = array_values(array_filter($member->invoke, fn ($i) => isset($i['process'])));
        $effectInvoke = array_values(array_filter($member->invoke, fn ($i) => isset($i['effect'])));
        $batchInvoke = array_values(array_filter($member->invoke, fn ($i) => isset($i['batch'])));
        if (isset($data['switch']) && is_array($data['switch'])) {
            $switch = (string) ($data['switch']['name'] ?? '');
            $node['enabled'] = $this->switches?->isOn($switch) ?? true;
            $data['switch'] = $switch;
        }

        if ($member->isFinal()) {
            $node['type'] = 'end';
        } elseif ($member->type->value === 'choice') {
            $node['type'] = 'decision';
        } elseif ($member->isCompound() || $member->isParallel()) {
            $node['type'] = 'subprocess';
            $node['subgraph'] = $this->levelGraph($definition, $member);
            if ($member->isParallel()) {
                $data['kind'] = 'parallel';
                $data['join'] = $member->join;
            }
        } elseif ($processInvoke !== []) {
            $class = (string) $processInvoke[0]['process'];
            $node['type'] = 'subprocess';
            $node['subgraph'] = is_subclass_of($class, Process::class) ? $class::processName().'@'.$class::processVersion() : $class;
            if (isset($processInvoke[0]['forEach'])) {
                $data['forEach'] = $processInvoke[0]['forEach'];
            }
        } elseif ($batchInvoke !== []) {
            $node['type'] = 'task';
            $data['batch'] = self::short((string) $batchInvoke[0]['batch']);
            $data['items'] = self::short((string) $batchInvoke[0]['items']);
        } elseif ($effectInvoke !== []) {
            $node['type'] = 'task';
            $data['effect'] = self::short((string) $effectInvoke[0]['effect']);
            if (isset($effectInvoke[0]['forEach'])) {
                $data['forEach'] = $effectInvoke[0]['forEach'];
            }
        } elseif ($member->isHistory()) {
            $node['type'] = 'state';
            $data['kind'] = 'history';
        } else {
            $node['type'] = 'state';
        }
        if ($member->polls !== []) {
            $data['poll'] = self::short((string) $member->polls[0]['effect']).' every '.self::spec((array) ($member->polls[0]['every'] ?? []));
        }
        if ($member->timers !== []) {
            $data['timers'] = count($member->timers);
        }
        if ($data !== []) {
            $node['data'] = self::values($data);
        }

        return $node;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function edges(Definition $definition, StateNode $level): array
    {
        $members = $this->members($level);
        $edges = [];
        $seen = [];
        foreach ($definition->nodes() as $source) {
            if ($source === $level || ! $source->isDescendantOf($level)) {
                continue;
            }
            $from = $this->memberOf($definition, $members, $source->id);
            if ($from === null) {
                continue;
            }
            foreach ([...$source->on, ...$source->always] as $transition) {
                if ($transition->target === null) {
                    continue;
                }
                $to = $this->memberOf($definition, $members, $transition->target);
                if ($to === null) {
                    continue;
                }
                // Inside one member and not a self-loop of the member itself: drawn one level deeper.
                if ($from === $to && $source !== $from) {
                    continue;
                }
                if ($from === $to && $definition->node($transition->target) !== $from) {
                    continue;
                }
                $edge = ['id' => 'edge:'.$transition->order, 'from' => $from->id, 'to' => $to->id];
                $label = $this->label($definition, $transition);
                if ($label !== null) {
                    $edge['label'] = $label;
                }
                $condition = $this->condition($transition);
                if ($condition !== null) {
                    $edge['condition'] = $condition;
                }
                $key = $edge['from'].'|'.$edge['to'].'|'.($edge['label'] ?? '').'|'.($edge['condition'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $edges[] = $edge;
            }
        }

        return $edges;
    }

    /**
     * @return list<array{node: array<string, mixed>, edge: array<string, mixed>}>
     */
    private function spawns(StateNode $level): array
    {
        $out = [];
        foreach ([$level, ...$this->members($level)] as $owner) {
            foreach ($owner->on as $transition) {
                foreach ($transition->actions as $action) {
                    if (($action['type'] ?? null) !== 'spawn') {
                        continue;
                    }
                    $class = (string) $action['process'];
                    $id = 'spawn:'.$action['id'];
                    $node = [
                        'id' => $id,
                        'type' => 'subprocess',
                        'label' => is_subclass_of($class, Process::class) ? $class::processName() : self::short($class),
                        'subgraph' => is_subclass_of($class, Process::class) ? $class::processName().'@'.$class::processVersion() : $class,
                        'data' => ['spawn' => 'on '.($transition->event ?? '')],
                    ];
                    $anchor = $owner === $level ? $this->members($level)[0] ?? null : $owner;
                    if ($anchor === null) {
                        continue;
                    }
                    $out[] = ['node' => $node, 'edge' => ['id' => 'edge:'.$id, 'from' => $anchor->id, 'to' => $id, 'kind' => 'association', 'label' => $transition->event ?? '']];
                }
            }
        }

        return $out;
    }

    private function label(Definition $definition, Transition $transition): ?string
    {
        if ($transition->label !== null) {
            return $transition->label;
        }
        $event = $transition->event;
        if ($event === null) {
            return null;
        }
        if (str_starts_with($event, 'after.')) {
            $timerId = substr($event, strlen('after.'));
            $owner = $definition->timerOwner($timerId);
            foreach ($owner !== null ? $owner->timers : [] as $timer) {
                if ($timer['id'] === $timerId) {
                    return '⏱ '.self::spec((array) ($timer['delay'] ?? $timer['at'] ?? []));
                }
            }

            return '⏱';
        }
        foreach (['done.invoke.' => 'done', 'error.invoke.' => 'error', 'unknown.invoke.' => 'unknown', 'done.state.' => 'done', 'done.compensation' => 'compensated'] as $prefix => $word) {
            if (str_starts_with($event, $prefix)) {
                return $word;
            }
        }
        if (class_exists($event)) {
            return self::short($event);
        }

        return $event;
    }

    private function condition(Transition $transition): ?string
    {
        $guard = $transition->guard;
        if ($guard === null) {
            return null;
        }
        if (($guard['type'] ?? null) === 'method') {
            return (string) ($guard['name'] ?? '');
        }
        $expr = (array) ($guard['expr'] ?? []);
        if (isset($expr['eq']) && is_array($expr['eq'])) {
            $path = (string) ($expr['eq'][0] ?? '');
            $field = (string) preg_replace('/^\$\.(event|context)\./', '', $path);

            return $field.' = '.(is_scalar($expr['eq'][1] ?? null) ? var_export($expr['eq'][1], true) : json_encode($expr['eq'][1] ?? null));
        }

        return Run::describeGuard($guard);
    }

    // Levels ------------------------------------------------------------------

    /**
     * @param  list<string>  $path
     */
    private function level(Definition $definition, array $path): StateNode
    {
        if ($path === []) {
            return $definition->root;
        }
        $id = (string) end($path);
        if (! $definition->has($id)) {
            throw new \InvalidArgumentException("Unknown state `{$id}` in the drill-down path");
        }
        $node = $definition->node($id);
        if (! $node->isCompound() && ! $node->isParallel()) {
            throw new \InvalidArgumentException("State `{$id}` has no inner level");
        }

        return $node;
    }

    /**
     * @return list<StateNode>
     */
    private function members(StateNode $level): array
    {
        if (! $level->isParallel()) {
            return $level->children;
        }
        $out = [];
        foreach ($level->stateChildren() as $region) {
            array_push($out, ...($region->children !== [] ? $region->children : [$region]));
        }

        return $out;
    }

    /**
     * The member of the level that contains the state (or is it).
     *
     * @param  list<StateNode>  $members
     */
    private function memberOf(Definition $definition, array $members, string $state): ?StateNode
    {
        if (! $definition->has($state)) {
            return null;
        }
        $node = $definition->node($state);
        foreach ($members as $member) {
            if ($member === $node || $node->isDescendantOf($member)) {
                return $member;
            }
        }

        return null;
    }

    private function effectIn(Definition $definition, EffectRecord $effect, StateNode $member): bool
    {
        $state = $effect->input['state'] ?? null;
        if (! is_string($state) || ! $definition->has($state)) {
            $activation = $effect->input['activation'] ?? null;
            $state = is_string($activation) ? substr($activation, 0, (int) strrpos($activation, '@')) : null;
        }
        if (! is_string($state) || ! $definition->has($state)) {
            return false;
        }
        $node = $definition->node($state);

        return $node === $member || $node->isDescendantOf($member);
    }

    private function waitingFor(InstanceView $instance, StateNode $member): string
    {
        $parts = [];
        foreach ($instance->expects() as $expected) {
            if ($expected->state === $member->id) {
                $parts[] = $expected->kind === 'timer' ? (string) $expected->label : ($expected->label ?? self::short($expected->event));
            }
        }

        return $parts === [] ? '' : 'waiting: '.implode(', ', array_unique($parts));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private static function spec(array $spec): string
    {
        return match (true) {
            isset($spec['duration']) => (string) $spec['duration'],
            isset($spec['businessDays']) => $spec['businessDays'].' business days',
            isset($spec['method']) => (string) $spec['method'].'()',
            isset($spec['path']) => (string) preg_replace('/^\$\.context\./', '', (string) $spec['path']),
            default => '',
        };
    }

    private static function short(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Flow data values are JSON values: objects and enums flattened.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function values(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = is_scalar($value) || $value === null || is_array($value) ? $value : (string) json_encode($value);
        }

        return $out;
    }
}
