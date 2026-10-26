<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Definition;

/**
 * One transition of the compiled definition. Guards and actions stay in their
 * canonical array form; the interpreter evaluates them.
 */
final class Transition
{
    /**
     * @param  array<string, mixed>|null  $guard
     * @param  list<array<string, mixed>>  $actions
     */
    public function __construct(
        public readonly StateNode $source,
        public readonly ?string $event,
        public readonly ?array $guard,
        public readonly array $actions,
        public readonly ?string $target,
        public readonly ?string $label,
        public readonly int $order,
    ) {}

    public function isTargetless(): bool
    {
        return $this->target === null;
    }

    public function matches(string $event): bool
    {
        if ($this->event === null) {
            return false;
        }
        if ($this->event === $event || $this->event === '*') {
            return true;
        }

        // `done.invoke.x` also matches `done.invoke.x.anything` is not used: names are exact.
        return false;
    }

    public function describe(): string
    {
        return $this->source->id.' → '.($this->target ?? '(internal)').($this->event !== null ? ' on '.$this->event : ' (always)');
    }
}
