<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Definition;

/**
 * One state of the compiled definition, with links to its parent and
 * children and its position in document order (SCXML uses it to order
 * entries, exits and conflicting transitions).
 */
final class StateNode
{
    /** @var list<StateNode> */
    public array $children = [];

    /** @var list<Transition> */
    public array $on = [];

    /** @var list<Transition> */
    public array $always = [];

    public int $order = 0;

    public int $depth = 0;

    /**
     * @param  list<array<string, mixed>>  $entry
     * @param  list<array<string, mixed>>  $exit
     * @param  list<array<string, mixed>>  $timers
     * @param  list<array<string, mixed>>  $invoke
     * @param  list<array<string, mixed>>  $polls
     * @param  list<string>  $defer
     * @param  list<array{events: list<string>, by: string}>  $correlate
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $output
     */
    public function __construct(
        public readonly string $id,
        public readonly StateType $type,
        public readonly ?StateNode $parent,
        public readonly ?string $initial,
        public readonly array $entry,
        public readonly array $exit,
        public readonly array $timers,
        public readonly array $invoke,
        public readonly array $polls,
        public readonly array $defer,
        public readonly array $correlate,
        public readonly array $meta,
        public readonly string $join,
        public readonly ?string $history,
        public readonly ?string $historyDefault,
        public readonly ?array $output,
    ) {}

    public function isAtomic(): bool
    {
        return $this->type === StateType::Atomic || $this->type === StateType::Final || $this->type === StateType::Choice;
    }

    public function isCompound(): bool
    {
        return $this->type === StateType::Compound;
    }

    public function isParallel(): bool
    {
        return $this->type === StateType::Parallel;
    }

    public function isFinal(): bool
    {
        return $this->type === StateType::Final;
    }

    public function isHistory(): bool
    {
        return $this->type === StateType::History;
    }

    /**
     * Ancestors from the parent up to the root (excluding this node).
     *
     * @return list<StateNode>
     */
    public function ancestors(?StateNode $upTo = null): array
    {
        $out = [];
        for ($node = $this->parent; $node !== null && $node !== $upTo; $node = $node->parent) {
            $out[] = $node;
        }

        return $out;
    }

    public function isDescendantOf(StateNode $other): bool
    {
        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            if ($node === $other) {
                return true;
            }
        }

        return false;
    }

    /**
     * Proper (non-history) children.
     *
     * @return list<StateNode>
     */
    public function stateChildren(): array
    {
        return array_values(array_filter($this->children, fn (StateNode $c) => ! $c->isHistory()));
    }

    /**
     * The full dotted path from the root (the root itself is not part of it).
     */
    public function path(): string
    {
        $ids = [];
        for ($node = $this; $node->parent !== null; $node = $node->parent) {
            array_unshift($ids, $node->id);
        }

        return implode('.', $ids);
    }

    public function label(): ?string
    {
        $label = $this->meta['label'] ?? null;

        return is_string($label) ? $label : null;
    }
}
