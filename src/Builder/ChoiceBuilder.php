<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

use BackedEnum;
use Closure;

/**
 * The branches of a choice state, tried in the order they are declared.
 */
final class ChoiceBuilder
{
    /** @var list<array<string, mixed>> */
    private array $transitions = [];

    /**
     * @param  Closure(list<array<string, mixed>>): void  $store
     */
    public function __construct(private readonly Compiler $compiler, private readonly Closure $store) {}

    public function when(Closure|Predicate $guard, string|BackedEnum $to, mixed $run = null): self
    {
        $this->transitions[] = [
            'guard' => $this->compiler->guard($guard),
            'actions' => $this->compiler->actions($run, 'choice'),
            'target' => Compiler::id($to),
        ];
        ($this->store)($this->transitions);

        return $this;
    }

    /**
     * A routing table on a context property: value → target.
     *
     * @param  array<string|int, string|BackedEnum>  $targets
     */
    public function match(string $path, array $targets, string|BackedEnum|null $otherwise = null): self
    {
        foreach ($targets as $value => $target) {
            $this->when(new Predicate(['eq' => [Predicate::path($path), $value]]), $target);
        }
        if ($otherwise !== null) {
            $this->otherwise($otherwise);
        }

        return $this;
    }

    public function otherwise(string|BackedEnum $to, mixed $run = null): self
    {
        $this->transitions[] = [
            'actions' => $this->compiler->actions($run, 'choice'),
            'target' => Compiler::id($to),
        ];
        ($this->store)($this->transitions);

        return $this;
    }
}
