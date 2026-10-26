<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Definition;

use Dskripchenko\ProcessEngine\Support\Json;

/**
 * A process definition: the canonical array (what is stored, hashed and
 * exported) and its compiled graph (what the interpreter walks).
 *
 * Canonical format, version 1:
 *
 *     {format: 1, name, version, class?, states?: enum class, projections?: [...],
 *      root: node}
 *     node = {id, type, initial?, states?: [node], entry?: [action], exit?: [action],
 *             on?: [transition], always?: [transition], timers?: [timer], invoke?: [invoke],
 *             polls?: [poll], defer?: [event], correlate?: [{events, by}], meta?: {},
 *             join?: all|any, history?: shallow|deep, default?: id, output?: value}
 *     transition = {event?, guard?, actions?: [action], target?: id, label?}
 *     action = {type: method|effect|assign|raise|spawn|compensate|log, ...}
 *     guard = {type: method, name} | {type: predicate, expr}
 *     timer = {id, delay?: spec, at?: spec}; spec = {duration}|{method}|{path}|{businessDays}
 *     invoke = {id, effect?: class, process?: class, into?, input?, forEach?, concurrency?, retry?}
 *     poll = {id, effect: class, every: spec}
 */
final class Definition
{
    public const FORMAT = 1;

    public const ROOT = '#root';

    public readonly StateNode $root;

    /** @var array<string, StateNode> */
    private array $nodes = [];

    /** @var array<string, StateNode> invoke id → owning state */
    private array $invokeOwners = [];

    /** @var array<string, array<string, mixed>> */
    private array $invokes = [];

    /** @var array<string, StateNode> timer id → owning state */
    private array $timerOwners = [];

    /** @var array<string, StateNode> poll id → owning state */
    private array $pollOwners = [];

    /** @var array<string, array<string, mixed>> */
    private array $polls = [];

    private int $transitionOrder = 0;

    private ?string $hash = null;

    /**
     * @param  array<string, mixed>  $data  the canonical form
     */
    public function __construct(private readonly array $data)
    {
        $root = $data['root'] ?? null;
        if (! is_array($root)) {
            throw new DefinitionError('A definition needs a `root` state');
        }
        $order = 0;
        $this->root = $this->compile($root, null, $order, 0);
        $this->compileTransitions($root, $this->root);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = Json::decodeArray($json);

        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return Json::encode($this->data);
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? '');
    }

    public function version(): int
    {
        return (int) ($this->data['version'] ?? 0);
    }

    /**
     * The process class whose methods guards and actions refer to, if any.
     */
    public function processClass(): ?string
    {
        $class = $this->data['class'] ?? null;

        return is_string($class) ? $class : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function projections(): array
    {
        $projections = $this->data['projections'] ?? [];

        return is_array($projections) ? array_values($projections) : [];
    }

    /**
     * SHA-256 of the canonical JSON (keys sorted).
     */
    public function hash(): string
    {
        return $this->hash ??= hash('sha256', Json::canonical($this->data));
    }

    public function key(): string
    {
        return $this->name().'@'.$this->version();
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function node(string $id): StateNode
    {
        return $this->nodes[$id] ?? throw new DefinitionError("Unknown state `{$id}` in {$this->key()}");
    }

    /**
     * @return array<string, StateNode>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function invokeOwner(string $invokeId): ?StateNode
    {
        return $this->invokeOwners[$invokeId] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function invoke(string $invokeId): ?array
    {
        return $this->invokes[$invokeId] ?? null;
    }

    public function timerOwner(string $timerId): ?StateNode
    {
        return $this->timerOwners[$timerId] ?? null;
    }

    public function pollOwner(string $pollId): ?StateNode
    {
        return $this->pollOwners[$pollId] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function poll(string $pollId): ?array
    {
        return $this->polls[$pollId] ?? null;
    }

    /**
     * Final states directly under the root: reaching one completes the process.
     *
     * @return list<string>
     */
    public function finals(?string $under = null): array
    {
        $node = $under === null ? $this->root : $this->node($under);

        return array_values(array_map(fn (StateNode $n) => $n->id, array_filter($node->children, fn (StateNode $n) => $n->isFinal())));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function compile(array $data, ?StateNode $parent, int &$order, int $depth): StateNode
    {
        $id = $data['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new DefinitionError('Every state needs a non-empty string `id`');
        }
        if (isset($this->nodes[$id])) {
            throw new DefinitionError("State id `{$id}` is used twice: ids are unique within a process");
        }
        $type = StateType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null) {
            $type = isset($data['states']) && $data['states'] !== [] ? StateType::Compound : StateType::Atomic;
        }

        $node = new StateNode(
            id: $id,
            type: $type,
            parent: $parent,
            initial: isset($data['initial']) && is_string($data['initial']) ? $data['initial'] : null,
            entry: self::listOf($data['entry'] ?? []),
            exit: self::listOf($data['exit'] ?? []),
            timers: self::listOf($data['timers'] ?? []),
            invoke: self::listOf($data['invoke'] ?? []),
            polls: self::listOf($data['polls'] ?? []),
            defer: array_values(array_map('strval', is_array($data['defer'] ?? null) ? $data['defer'] : [])),
            correlate: self::correlations($data['correlate'] ?? []),
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
            join: ($data['join'] ?? 'all') === 'any' ? 'any' : 'all',
            history: isset($data['history']) && is_string($data['history']) ? $data['history'] : null,
            historyDefault: isset($data['default']) && is_string($data['default']) ? $data['default'] : null,
            output: isset($data['output']) && is_array($data['output']) ? $data['output'] : null,
        );
        $node->order = $order++;
        $node->depth = $depth;
        $this->nodes[$id] = $node;

        foreach ($node->invoke as $invoke) {
            $invokeId = (string) ($invoke['id'] ?? '');
            if ($invokeId === '' || isset($this->invokeOwners[$invokeId])) {
                throw new DefinitionError("Invoke ids must be unique and non-empty (state `{$id}`)");
            }
            $this->invokeOwners[$invokeId] = $node;
            $this->invokes[$invokeId] = $invoke;
        }
        foreach ($node->timers as $timer) {
            $timerId = (string) ($timer['id'] ?? '');
            if ($timerId === '' || isset($this->timerOwners[$timerId])) {
                throw new DefinitionError("Timer ids must be unique and non-empty (state `{$id}`)");
            }
            $this->timerOwners[$timerId] = $node;
        }
        foreach ($node->polls as $poll) {
            $pollId = (string) ($poll['id'] ?? '');
            if ($pollId === '' || isset($this->pollOwners[$pollId])) {
                throw new DefinitionError("Poll ids must be unique and non-empty (state `{$id}`)");
            }
            $this->pollOwners[$pollId] = $node;
            $this->polls[$pollId] = $poll;
        }

        foreach (self::listOf($data['states'] ?? []) as $child) {
            $node->children[] = $this->compile($child, $node, $order, $depth + 1);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function compileTransitions(array $data, StateNode $node): void
    {
        foreach (self::listOf($data['on'] ?? []) as $t) {
            $node->on[] = $this->transition($node, $t, true);
        }
        foreach (self::listOf($data['always'] ?? []) as $t) {
            $node->always[] = $this->transition($node, $t, false);
        }
        $children = self::listOf($data['states'] ?? []);
        foreach ($node->children as $i => $child) {
            $this->compileTransitions($children[$i], $child);
        }
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function transition(StateNode $node, array $t, bool $withEvent): Transition
    {
        $event = $withEvent ? ($t['event'] ?? null) : null;
        if ($withEvent && (! is_string($event) || $event === '')) {
            throw new DefinitionError("A transition of `{$node->id}` has no event");
        }
        $target = $t['target'] ?? null;

        return new Transition(
            source: $node,
            event: is_string($event) ? $event : null,
            guard: isset($t['guard']) && is_array($t['guard']) ? $t['guard'] : null,
            actions: self::listOf($t['actions'] ?? []),
            target: is_string($target) && $target !== '' ? $target : null,
            label: isset($t['label']) && is_string($t['label']) ? $t['label'] : null,
            order: $this->transitionOrder++,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @return list<array{events: list<string>, by: string}>
     */
    private static function correlations(mixed $value): array
    {
        $out = [];
        foreach (self::listOf($value) as $c) {
            $events = is_array($c['events'] ?? null) ? array_values(array_map('strval', $c['events'])) : [];
            $out[] = ['events' => $events, 'by' => (string) ($c['by'] ?? '')];
        }

        return $out;
    }
}
