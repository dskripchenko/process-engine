<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Testing;

use Closure;
use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Query\InstanceView;
use Dskripchenko\ProcessEngine\Runtime\CollectingListener;
use Dskripchenko\ProcessEngine\Runtime\Container;
use Dskripchenko\ProcessEngine\Runtime\EngineOptions;
use Dskripchenko\ProcessEngine\Runtime\FaultInjector;
use Dskripchenko\ProcessEngine\Runtime\SimulatedCrash;
use Dskripchenko\ProcessEngine\Runtime\SyncDispatcher;
use Dskripchenko\ProcessEngine\Runtime\VirtualClock;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\InMemoryStore;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Support\Duration;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A process under test: an in-memory engine with a virtual clock, fake
 * effects and fault injection, driven synchronously.
 *
 *     ProcessTest::start(ClaimSettlement::class, new ClaimContext(policyId: 'P-1'))
 *         ->fake(SubmitToInsurer::class, returns: 'APP-1')
 *         ->send(new FormSubmitted([...]))
 *         ->travel('10 days')
 *         ->assertIn('awaitingParty');
 */
final class ProcessTest
{
    public readonly Engine $engine;

    public readonly VirtualClock $clock;

    public readonly FaultInjector $faults;

    public readonly FakeContainer $container;

    public readonly CollectingListener $signals;

    private ?string $instanceId = null;

    /** @var list<SimulatedCrash> */
    public array $crashes = [];

    public function __construct(
        ?Store $store = null,
        ?VirtualClock $clock = null,
        ?Container $container = null,
        EngineOptions $options = new EngineOptions,
    ) {
        $this->clock = $clock ?? new VirtualClock;
        $this->faults = new FaultInjector;
        $this->container = new FakeContainer($container);
        $this->signals = new CollectingListener;
        $this->engine = new Engine(
            store: $store ?? new InMemoryStore,
            container: $this->container,
            dispatcher: new SyncDispatcher,
            clock: $this->clock,
            options: $options,
            listener: $this->signals,
            faults: $this->faults,
        );
    }

    /**
     * Start an instance and drain the work it created. Fakes registered with
     * `$fakes` are in place before the first effect runs.
     *
     * @param  class-string<Process>|string  $process
     * @param  object|array<string, mixed>  $context
     * @param  array<class-string, mixed>  $fakes  class → return value
     */
    public static function start(string $process, object|array $context = [], array $fakes = [], ?string $key = null): self
    {
        $test = new self;
        foreach ($fakes as $class => $returns) {
            $test->fake($class, returns: $returns);
        }

        return $test->begin($process, $context, $key);
    }

    /**
     * @param  class-string<Process>|string  $process
     * @param  object|array<string, mixed>  $context
     */
    public function begin(string $process, object|array $context = [], ?string $key = null): self
    {
        $this->instanceId = $this->engine->start($process, $context, $key)->id();

        return $this->drain();
    }

    /**
     * Replace an effect handler. `crash: 'after-call'` makes the worker die
     * after the call returned and before the result was recorded.
     *
     * @param  list<mixed>  $sequence
     */
    public function fake(
        string $class,
        mixed $returns = null,
        ?Throwable $throws = null,
        ?string $crash = null,
        array $sequence = [],
        ?Closure $using = null,
        Outcome|Throwable|null $reconcile = null,
        ?Throwable $compensateThrows = null,
    ): self {
        $this->container->fakes[$class] = new FakeEffect($this->faults, $returns, $throws, $crash, $sequence, $using, $reconcile, $compensateThrows);

        return $this;
    }

    public function fakeOf(string $class): FakeEffect
    {
        return $this->container->fakes[$class] ?? throw new \LogicException("{$class} is not faked");
    }

    public function id(): string
    {
        return $this->instanceId ?? throw new \LogicException('No instance started');
    }

    public function view(?string $id = null): InstanceView
    {
        return $this->engine->get($id ?? $this->id());
    }

    // Driving -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(object|string $event, array $payload = [], ?string $key = null, ?Actor $by = null): self
    {
        $this->engine->send($this->id(), $event, $payload, $key, $by);

        return $this->drain();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(object|string $event, string $correlate, array $payload = [], ?string $key = null): self
    {
        $this->engine->publish($event, $correlate, $payload, $key);

        return $this->drain();
    }

    /**
     * Move the clock forward step by step (so timers and retries fire in
     * order) and work off what becomes due.
     */
    public function travel(string $duration, string $step = '1 hour'): self
    {
        $target = Duration::add($this->clock->now(), $duration);
        $stepSeconds = max(1, min(Duration::seconds($step), Duration::seconds($duration)));
        while ($this->clock->now() < $target) {
            $next = $this->clock->now()->modify("+{$stepSeconds} seconds");
            $this->clock->set($next > $target ? $target : $next);
            $this->tick();
        }

        return $this;
    }

    public function tick(): self
    {
        $this->guard(fn () => $this->engine->tick());

        return $this->drain();
    }

    /**
     * After a crash: let leases expire and the sweeper pick up the pieces.
     */
    public function recover(string $after = '10 minutes'): self
    {
        $this->faults->disarm();

        return $this->travel($after, '1 minute');
    }

    public function crashAt(string $point, int $skip = 0): self
    {
        $this->faults->crashAt($point, $skip);

        return $this;
    }

    public function drain(): self
    {
        for ($i = 0; $i < 1000; $i++) {
            $ran = $this->guard(fn () => $this->engine->drain(1));
            if ($ran === 0) {
                break;
            }
        }

        return $this;
    }

    // Operator actions --------------------------------------------------------

    public function operator(): Actor
    {
        return Actor::operator('tester');
    }

    public function resolveEffect(string $class, bool $succeeded = true, mixed $result = null): self
    {
        $this->engine->resolveEffect($this->effect($class)->id, $this->operator(), 'checked by hand', $succeeded, $result);

        return $this->drain();
    }

    // Assertions --------------------------------------------------------------

    public function assertIn(string|\BackedEnum ...$states): self
    {
        $view = $this->view();
        foreach ($states as $state) {
            $id = $state instanceof \BackedEnum ? (string) $state->value : $state;
            Assert::assertTrue($view->is($id), "Expected the instance to be in `{$id}`, it is in [".implode(', ', $view->active())."] ({$view->status()})");
        }

        return $this;
    }

    public function assertNotIn(string ...$states): self
    {
        foreach ($states as $state) {
            Assert::assertFalse($this->view()->is($state), "Expected the instance not to be in `{$state}`");
        }

        return $this;
    }

    public function assertFinal(string|\BackedEnum $state): self
    {
        $id = $state instanceof \BackedEnum ? (string) $state->value : $state;
        $view = $this->view();
        Assert::assertTrue($view->isFinished(), "Expected the instance to be finished, it is {$view->status()} in [".implode(', ', $view->active()).']');
        Assert::assertSame($id, $view->final());

        return $this;
    }

    public function assertStatus(string $status): self
    {
        Assert::assertSame($status, $this->view()->status());

        return $this;
    }

    /**
     * How many times the faked handler was called.
     */
    public function assertCalls(string $class, int $times): self
    {
        Assert::assertCount($times, $this->fakeOf($class)->calls, "{$class} was called ".count($this->fakeOf($class)->calls).' times');

        return $this;
    }

    /**
     * How many effects of the class succeeded for the instance.
     */
    public function assertRan(string $class, int $times = 1): self
    {
        $ran = array_filter($this->view()->effects([EffectRecord::SUCCEEDED, EffectRecord::COMPENSATED]), fn (EffectRecord $e) => $e->class === $class && $e->kind !== EffectRecord::KIND_COMPENSATION);
        Assert::assertCount($times, $ran, "{$class} succeeded ".count($ran).' times');

        return $this;
    }

    public function assertEffect(string $class, string $status): self
    {
        Assert::assertSame($status, $this->effect($class)->status);

        return $this;
    }

    public function assertIncident(string $type): self
    {
        $types = array_map(fn ($i) => $i->type, $this->view()->incidents());
        Assert::assertContains($type, $types, 'Open incidents: ['.implode(', ', $types).']');

        return $this;
    }

    public function assertNoIncidents(): self
    {
        $incidents = $this->view()->incidents();
        Assert::assertSame([], array_map(fn ($i) => $i->type.': '.$i->message, $incidents));

        return $this;
    }

    public function assertContext(string $key, mixed $value): self
    {
        $context = $this->view()->contextArray();
        $actual = $context;
        foreach (explode('.', $key) as $segment) {
            $actual = is_array($actual) ? ($actual[$segment] ?? null) : null;
        }
        Assert::assertSame($value, $actual);

        return $this;
    }

    public function effect(string $class): EffectRecord
    {
        $matches = array_values(array_filter($this->view()->effects(), fn (EffectRecord $e) => $e->class === $class && $e->kind !== EffectRecord::KIND_COMPENSATION));
        if ($matches === []) {
            throw new \LogicException("No effect of {$class}");
        }

        return $matches[count($matches) - 1];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T|0
     */
    private function guard(Closure $work): mixed
    {
        try {
            return $work();
        } catch (SimulatedCrash $crash) {
            $this->crashes[] = $crash;

            return 0;
        }
    }
}
