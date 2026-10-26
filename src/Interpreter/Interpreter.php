<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\Transition;
use Dskripchenko\ProcessEngine\Event;
use InvalidArgumentException;

/**
 * The step as a pure function:
 *
 *     step(definition, snapshot, event, step context) → (snapshot', commands, journal data)
 *
 * No storage, no clock (the time comes in the step context), no side effects:
 * effects, timers, polls and children come out as commands. The semantics
 * follow the SCXML algorithm: transition selection from the deepest active
 * states up, conflict removal, exit in reverse document order, entry in
 * document order, eventless transitions and internal events until stable.
 */
final class Interpreter
{
    public const MAX_MICROSTEPS = 100;

    public function __construct(
        private readonly ?FlagProvider $flags = null,
        private readonly BusinessCalendar $calendar = new WeekdayCalendar,
        private readonly int $maxMicrosteps = self::MAX_MICROSTEPS,
    ) {}

    /**
     * Enter the initial configuration of a new instance.
     *
     * @param  array<string, mixed>  $context
     */
    public function start(Definition $definition, array $context, ?string $contextClass, StepContext $ctx): StepResult
    {
        $run = new Run($this, $definition, new Snapshot(context: $context, contextClass: $contextClass), $ctx);
        $run->enterRoot();
        $run->settle();

        return $run->result(StepResult::TRANSITIONED);
    }

    /**
     * Enter a given configuration without running entry actions: for
     * instances adopted from outside the engine. Timers, polls, subscriptions
     * and invocations of the entered states start as usual.
     *
     * @param  list<string>  $states
     * @param  array<string, mixed>  $context
     * @param  array<string, int>  $activations  counters to continue from (a migration keeps keys unique)
     */
    public function adopt(Definition $definition, array $states, array $context, ?string $contextClass, StepContext $ctx, array $activations = []): StepResult
    {
        $run = new Run($this, $definition, new Snapshot(context: $context, contextClass: $contextClass, activations: $activations), $ctx);
        $run->runEntryActions = false;
        $run->enterRoot(enterInitial: false);
        $run->enterTargets($states, $definition->root);
        $run->runEntryActions = true;
        $run->transitions[] = ['source' => null, 'target' => implode(',', $states), 'event' => 'adopted'];

        return $run->result(StepResult::TRANSITIONED);
    }

    public function step(Definition $definition, Snapshot $snapshot, Event $event, StepContext $ctx): StepResult
    {
        $run = new Run($this, $definition, clone $snapshot, $ctx);
        if ($snapshot->status !== Snapshot::RUNNING) {
            return $run->result(StepResult::IGNORED, reason: "the instance is {$snapshot->status}");
        }

        $stale = $run->staleReason($event);
        if ($stale !== null) {
            return $run->result(StepResult::DISCARDED, reason: $stale);
        }

        if (str_starts_with($event->name, 'item.invoke.')) {
            $event = $run->collect($event);
            if ($event === null) {
                return $run->result(StepResult::COLLECTED);
            }
        }

        $enabled = $run->select($event);
        if ($enabled === []) {
            if ($run->defers($event->name)) {
                return $run->result(StepResult::DEFERRED, reason: 'deferred by the active state');
            }

            return $run->result(StepResult::IGNORED, reason: 'no transition accepts the event', expected: $run->expectedNames());
        }

        $run->microstep($enabled, $event);
        $run->settle();

        return $run->result(StepResult::TRANSITIONED);
    }

    /**
     * Move the instance to the given states regardless of guards (an operator
     * action). Exit and entry actions run unless switched off.
     *
     * @param  list<string>  $states
     */
    public function transitionTo(Definition $definition, Snapshot $snapshot, array $states, StepContext $ctx, bool $runExit = true, bool $runEntry = true): StepResult
    {
        foreach ($states as $state) {
            if (! $definition->has($state)) {
                throw new InvalidArgumentException("Unknown state `{$state}`");
            }
        }
        $run = new Run($this, $definition, clone $snapshot, $ctx);
        $run->runExitActions = $runExit;
        $run->exitAll();
        $run->runExitActions = true;
        $run->runEntryActions = $runEntry;
        $run->enterTargets($states, $definition->root);
        $run->runEntryActions = true;
        $run->transitions[] = ['source' => null, 'target' => implode(',', $states), 'event' => 'manual.transition'];
        $run->settle();

        return $run->result(StepResult::TRANSITIONED);
    }

    /**
     * End the instance: every activation is cancelled, no exit actions run.
     */
    public function cancel(Definition $definition, Snapshot $snapshot, StepContext $ctx): StepResult
    {
        $run = new Run($this, $definition, clone $snapshot, $ctx);
        $run->runExitActions = false;
        $run->exitAll();
        $run->exitRoot();
        $run->snapshot->status = Snapshot::CANCELLED;

        return $run->result(StepResult::TRANSITIONED);
    }

    /**
     * Events the configuration accepts now, guards on the context evaluated.
     *
     * @return list<ExpectedEvent>
     */
    public function expects(Definition $definition, Snapshot $snapshot, StepContext $ctx): array
    {
        if ($snapshot->status !== Snapshot::RUNNING) {
            return [];
        }
        $run = new Run($this, $definition, clone $snapshot, $ctx);

        return $run->expected();
    }

    /**
     * When the poll should run next.
     */
    public function nextPoll(Definition $definition, Snapshot $snapshot, string $pollId, StepContext $ctx): DateTimeImmutable
    {
        $poll = $definition->poll($pollId) ?? throw new InvalidArgumentException("Unknown poll `{$pollId}`");
        $run = new Run($this, $definition, clone $snapshot, $ctx);

        return $run->moment((array) ($poll['every'] ?? []), true) ?? $ctx->now()->modify('+1 minute');
    }

    /**
     * @internal
     */
    public function flags(): ?FlagProvider
    {
        return $this->flags;
    }

    /**
     * @internal
     */
    public function calendar(): BusinessCalendar
    {
        return $this->calendar;
    }

    /**
     * @internal
     */
    public function maxMicrosteps(): int
    {
        return $this->maxMicrosteps;
    }
}
