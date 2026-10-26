<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Versioning;

use BackedEnum;
use Closure;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Query\InstanceView;

/**
 * Moves instances of one version of a process to another: which old state
 * becomes which new one, how the context changes, which instances qualify.
 *
 *     Migration::from(ClaimSettlement::class, 1)->to(2)
 *         ->mapState('review.waiting', 'awaitingParty')
 *         ->dropState('legacyFax')
 *         ->context(fn (array $c) => [...$c, 'requests' => 0])
 *         ->when(fn (InstanceView $i) => $i->incidents() === []);
 *
 * Migrations are code run by an operator, not part of a definition, so
 * closures are fine here.
 */
final class Migration
{
    /** @var array<string, string> */
    private array $states = [];

    /** @var list<string> */
    private array $dropped = [];

    private ?Closure $context = null;

    private ?Closure $when = null;

    private ?int $target = null;

    private function __construct(public readonly string $process, public readonly int $source) {}

    /**
     * @param  class-string<Process>|string  $process  a class or a stored process name
     */
    public static function from(string $process, int $version): self
    {
        $name = is_subclass_of($process, Process::class) ? $process::processName() : $process;

        return new self($name, $version);
    }

    public function to(int $version): self
    {
        $this->target = $version;

        return $this;
    }

    public function mapState(string|BackedEnum $from, string|BackedEnum $to): self
    {
        $this->states[self::id($from)] = self::id($to);

        return $this;
    }

    /**
     * Instances standing in this state are not migrated.
     */
    public function dropState(string|BackedEnum $state): self
    {
        $this->dropped[] = self::id($state);

        return $this;
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $transform
     */
    public function context(Closure $transform): self
    {
        $this->context = $transform;

        return $this;
    }

    /**
     * @param  Closure(InstanceView): bool  $condition
     */
    public function when(Closure $condition): self
    {
        $this->when = $condition;

        return $this;
    }

    public function name(): string
    {
        return "{$this->process}@{$this->source}->{$this->target()}";
    }

    public function target(): int
    {
        return $this->target ?? throw new \LogicException("Migration of {$this->process} from {$this->source} has no target version: call to()");
    }

    public function mapsTo(string $state): ?string
    {
        if (in_array($state, $this->dropped, true)) {
            return null;
        }

        return $this->states[$state] ?? $state;
    }

    public function isDropped(string $state): bool
    {
        return in_array($state, $this->dropped, true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function transform(array $context): array
    {
        return $this->context !== null ? ($this->context)($context) : $context;
    }

    public function accepts(InstanceView $view): bool
    {
        return $this->when === null || ($this->when)($view);
    }

    /**
     * @return array<string, string>
     */
    public function stateMap(): array
    {
        return $this->states;
    }

    private static function id(string|BackedEnum $state): string
    {
        return $state instanceof BackedEnum ? (string) $state->value : $state;
    }
}
