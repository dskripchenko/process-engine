<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\EffectFailed;
use Dskripchenko\ProcessEngine\Effect\EffectNotExecuted;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Effect\Publish;
use Dskripchenko\ProcessEngine\Effect\Reconcilable;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Effect\Send;
use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Support\Arguments;
use Dskripchenko\ProcessEngine\Support\Duration;
use Dskripchenko\ProcessEngine\Support\Hydrator;
use ReflectionMethod;
use Throwable;

/**
 * Runs one effect: claims it (the claim is the "started" mark committed
 * before the call), calls the handler outside any transaction and lock,
 * then records the outcome and delivers it to the process as an event.
 *
 *   success               → succeeded, `done.invoke`
 *   EffectNotExecuted     → retried (any semantics) while attempts last
 *   EffectFailed          → failed, `error.invoke` with the code, no retry
 *   anything else         → idempotent: retried; at-most-once: unknown,
 *                           `unknown.invoke`, reconciliation if possible
 *
 * @internal
 */
final class EffectExecutor
{
    public function __construct(private readonly Kernel $k) {}

    public function execute(string $effectId): void
    {
        $effect = $this->k->store->findEffect($effectId);
        if ($effect === null) {
            return;
        }
        if ($effect->status === EffectRecord::UNKNOWN) {
            $this->reconcile($effect);

            return;
        }
        if ($effect->status !== EffectRecord::PENDING) {
            return;
        }
        // A suspended instance starts nothing; the tick rings the effect again after resume.
        if ($this->k->store->findInstance($effect->instanceId)?->status === Snapshot::SUSPENDED) {
            return;
        }

        $retry = Retry::fromArray($effect->retry);
        $now = $this->k->now();
        $claimed = $this->k->store->claimEffect(
            $effect->id,
            EffectRecord::PENDING,
            EffectRecord::RUNNING,
            $this->k->worker,
            Duration::add($now, $retry->attemptTimeout),
            $now,
            $effect->kind !== EffectRecord::KIND_POLL,
        );
        if ($claimed === null) {
            return;
        }
        $this->k->faults->hit('effect.after_claim');

        match ($claimed->kind) {
            EffectRecord::KIND_POLL => $this->poll($claimed),
            EffectRecord::KIND_COMPENSATION => $this->compensation($claimed),
            EffectRecord::KIND_BATCH => $this->chunk($claimed),
            default => $this->attempt($claimed),
        };
    }

    /**
     * A chunk of a batch (ADR-014): the handler outside any transaction, then
     * the outcomes of its elements in one; the feeder only refills.
     */
    private function chunk(EffectRecord $effect): void
    {
        try {
            $result = $this->k->batches()->call($effect);
        } catch (SimulatedCrash $e) {
            throw $e;
        } catch (EffectNotExecuted $e) {
            $this->k->tx(fn () => $this->retryOrExhaust($effect, $e));

            return;
        } catch (EffectFailed $e) {
            $this->k->tx(fn () => $this->fail($effect, $e->errorCode, $e->getMessage(), $e->details));

            return;
        } catch (Throwable $e) {
            $this->k->tx(fn () => $this->ambiguous($effect, $e));

            return;
        }
        $this->k->faults->hit('batch.after_call');
        if ($result === null) {
            $this->k->batches()->fed($effect);

            return;
        }
        $this->k->batches()->complete($effect, $result);
        $this->k->faults->hit('batch.after_record');
    }

    private function attempt(EffectRecord $effect): void
    {
        try {
            $result = $this->call($effect, '__invoke');
        } catch (SimulatedCrash $e) {
            throw $e;
        } catch (EffectNotExecuted $e) {
            $this->k->tx(fn () => $this->retryOrExhaust($effect, $e));

            return;
        } catch (EffectFailed $e) {
            $this->k->tx(fn () => $this->fail($effect, $e->errorCode, $e->getMessage(), $e->details));

            return;
        } catch (Throwable $e) {
            $this->k->tx(fn () => $this->ambiguous($effect, $e));

            return;
        }
        $this->k->faults->hit('effect.after_call');
        $this->k->tx(fn () => $this->succeed($effect, $result), 'effect.after_record');
    }

    // Outcomes ----------------------------------------------------------------

    public function succeed(EffectRecord $effect, mixed $result, string $journalType = 'effect.succeeded'): void
    {
        $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
        $output = $this->route($fresh, $result);

        if ($fresh->status === EffectRecord::CANCELLED) {
            $fresh->result = $output;
            $this->k->store->updateEffect($fresh);
            $this->k->journal($fresh->instanceId, 'effect.orphaned', ['key' => $fresh->key, 'class' => $fresh->class, 'result' => $output]);

            return;
        }

        $fresh->status = EffectRecord::SUCCEEDED;
        $fresh->result = $output;
        $fresh->error = null;
        $fresh->finishedAt = $this->k->now();
        $fresh->lockedBy = null;
        $fresh->leaseUntil = null;
        $fresh->nextAttemptAt = null;
        $this->k->store->updateEffect($fresh);
        $this->k->journal($fresh->instanceId, $journalType, ['key' => $fresh->key, 'class' => $fresh->class, 'attempt' => $fresh->attempt, 'result' => $output]);
        $this->k->signal('effect.succeeded', $fresh->instanceId, ['effect' => $fresh->id, 'class' => $fresh->class]);

        if ($fresh->kind === EffectRecord::KIND_INVOKE) {
            $invoke = (string) ($fresh->input['invoke'] ?? '');
            if ($fresh->input['index'] !== null) {
                $this->k->deliver($fresh->instanceId, 'item.invoke.'.$invoke, [
                    'activation' => $fresh->input['activation'], 'index' => $fresh->input['index'], 'output' => $output, 'effect' => $fresh->id,
                ], 'effect:'.$fresh->id.':done');
            } else {
                $this->k->deliver($fresh->instanceId, 'done.invoke.'.$invoke, [
                    'activation' => $fresh->input['activation'], 'output' => $output, 'effect' => $fresh->id,
                ], 'effect:'.$fresh->id.':done');
            }
        }
        $this->promoteWaiting($fresh);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function fail(EffectRecord $effect, string $code, string $message, array $details = [], bool $exhausted = false): void
    {
        $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
        if ($fresh->status === EffectRecord::CANCELLED) {
            return;
        }
        if ($fresh->kind === EffectRecord::KIND_BATCH) {
            $this->k->journal($fresh->instanceId, 'effect.failed', ['key' => $fresh->key, 'class' => $fresh->class, 'attempt' => $fresh->attempt, 'code' => $code, 'message' => $message]);
            $exhausted
                ? $this->k->batches()->exhausted($fresh, $message)
                : $this->k->batches()->stop($fresh, $code, $message);

            return;
        }
        $fresh->status = EffectRecord::FAILED;
        $fresh->error = ['code' => $code, 'message' => $message, 'details' => $details];
        $fresh->finishedAt = $this->k->now();
        $fresh->lockedBy = null;
        $fresh->leaseUntil = null;
        $fresh->nextAttemptAt = null;
        $this->k->store->updateEffect($fresh);
        $this->k->journal($fresh->instanceId, 'effect.failed', ['key' => $fresh->key, 'class' => $fresh->class, 'attempt' => $fresh->attempt, 'code' => $code, 'message' => $message]);
        $this->k->signal('effect.failed', $fresh->instanceId, ['effect' => $fresh->id, 'class' => $fresh->class, 'code' => $code]);

        $policy = Retry::fromArray($fresh->retry)->onExhausted;
        if ($fresh->kind !== EffectRecord::KIND_INVOKE) {
            $this->k->incident($fresh->instanceId, $exhausted ? IncidentRecord::EFFECT_EXHAUSTED : IncidentRecord::EFFECT_FAILED, "{$fresh->class}: {$message}", ['code' => $code, 'key' => $fresh->key], $fresh->id);
        } elseif ($exhausted && $policy === Retry::ON_EXHAUSTED_INCIDENT) {
            $this->k->incident($fresh->instanceId, IncidentRecord::EFFECT_EXHAUSTED, "{$fresh->class} ran out of attempts: {$message}", ['code' => $code, 'key' => $fresh->key], $fresh->id);
        } elseif ($exhausted && $policy === Retry::ON_EXHAUSTED_FAIL) {
            $this->k->deliver($fresh->instanceId, 'manual.fail', ['reason' => "{$fresh->class} ran out of attempts: {$message}", 'effect' => $fresh->id], 'effect:'.$fresh->id.':fail', Actor::system());
        } else {
            $this->k->deliver($fresh->instanceId, 'error.invoke.'.$fresh->input['invoke'], [
                'activation' => $fresh->input['activation'], 'code' => $code, 'message' => $message, 'effect' => $fresh->id, 'index' => $fresh->input['index'],
            ], 'effect:'.$fresh->id.':error');
        }
        $this->promoteWaiting($fresh);
    }

    public function markUnknown(EffectRecord $effect, string $reason): void
    {
        $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
        if (! in_array($fresh->status, [EffectRecord::RUNNING, EffectRecord::RECONCILING], true)) {
            return;
        }
        $reconcilable = is_subclass_of($fresh->class, Reconcilable::class);
        $fresh->status = EffectRecord::UNKNOWN;
        $fresh->error = ['code' => 'unknown', 'message' => $reason];
        $fresh->lockedBy = null;
        $fresh->leaseUntil = null;
        $fresh->nextAttemptAt = $reconcilable ? $this->k->now() : null;
        $this->k->store->updateEffect($fresh);
        $this->k->journal($fresh->instanceId, 'effect.unknown', ['key' => $fresh->key, 'class' => $fresh->class, 'attempt' => $fresh->attempt, 'reason' => $reason, 'reconcilable' => $reconcilable]);
        $this->k->signal('effect.unknown', $fresh->instanceId, ['effect' => $fresh->id, 'class' => $fresh->class]);

        if ($fresh->kind === EffectRecord::KIND_INVOKE) {
            $this->k->deliver($fresh->instanceId, 'unknown.invoke.'.$fresh->input['invoke'], [
                'activation' => $fresh->input['activation'], 'effect' => $fresh->id, 'reconcilable' => $reconcilable,
            ], 'effect:'.$fresh->id.':unknown');
        } elseif (! $reconcilable) {
            $this->k->incident($fresh->instanceId, IncidentRecord::EFFECT_UNKNOWN, "The outcome of {$fresh->class} is unknown", ['key' => $fresh->key], $fresh->id);
        }
        if ($reconcilable) {
            $this->k->ringEffect($fresh->id);
        }
    }

    private function retryOrExhaust(EffectRecord $effect, Throwable $e): void
    {
        $retry = Retry::fromArray($effect->retry);
        if ($retry->exhausted($effect->attempt)) {
            $this->fail($effect, 'retries_exhausted', $e->getMessage(), ['exception' => $e::class], exhausted: true);

            return;
        }
        $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
        if ($fresh->status === EffectRecord::CANCELLED) {
            return;
        }
        $fresh->status = EffectRecord::PENDING;
        $fresh->error = ['code' => 'retry', 'message' => $e->getMessage(), 'exception' => $e::class];
        $fresh->lockedBy = null;
        $fresh->leaseUntil = null;
        $next = $retry->nextAttemptAt($this->k->now(), $effect->attempt);
        $fresh->nextAttemptAt = $next;
        $this->k->store->updateEffect($fresh);
        $this->k->journal($fresh->instanceId, 'effect.retry', [
            'key' => $fresh->key, 'class' => $fresh->class, 'attempt' => $fresh->attempt, 'message' => $e->getMessage(),
            'next' => $next->format(DATE_RFC3339_EXTENDED),
        ]);
    }

    private function ambiguous(EffectRecord $effect, Throwable $e): void
    {
        if ($effect->isAtMostOnce()) {
            $this->markUnknown($effect, $e->getMessage());

            return;
        }
        $nonRetryable = (array) ($effect->retry['nonRetryable'] ?? []);
        foreach ($nonRetryable as $match) {
            if (is_a($e, (string) $match) || (string) $e->getCode() === (string) $match) {
                $this->fail($effect, $this->code($e), $e->getMessage(), ['exception' => $e::class]);

                return;
            }
        }
        $this->retryOrExhaust($effect, $e);
    }

    // Reconciliation ----------------------------------------------------------

    private function reconcile(EffectRecord $effect): void
    {
        if (! is_subclass_of($effect->class, Reconcilable::class) || ! method_exists($effect->class, 'reconcile')) {
            return;
        }
        $policy = $this->reconcilePolicy($effect);
        $now = $this->k->now();
        $claimed = $this->k->store->claimEffect($effect->id, EffectRecord::UNKNOWN, EffectRecord::RECONCILING, $this->k->worker, Duration::add($now, $policy->attemptTimeout), $now, false);
        if ($claimed === null) {
            return;
        }
        try {
            $outcome = $this->call($claimed, 'reconcile');
        } catch (SimulatedCrash $e) {
            throw $e;
        } catch (Throwable $e) {
            $outcome = Outcome::stillUnknown();
        }
        $this->k->faults->hit('reconcile.after_call');

        $this->k->tx(function () use ($claimed, $outcome, $policy): void {
            if (! $outcome instanceof Outcome) {
                $outcome = Outcome::stillUnknown();
            }
            switch ($outcome->status) {
                case Outcome::SUCCEEDED:
                    $this->succeed($claimed, $outcome->result, 'effect.reconciled');
                    break;
                case Outcome::FAILED:
                    $this->fail($claimed, (string) $outcome->code, (string) ($outcome->message ?? $outcome->code));
                    break;
                case Outcome::NOT_EXECUTED:
                    $fresh = $this->k->store->findEffect($claimed->id) ?? $claimed;
                    $fresh->status = EffectRecord::PENDING;
                    $fresh->nextAttemptAt = $this->k->now();
                    $fresh->lockedBy = null;
                    $fresh->leaseUntil = null;
                    $this->k->store->updateEffect($fresh);
                    $this->k->journal($fresh->instanceId, 'effect.reconciled', ['key' => $fresh->key, 'outcome' => 'not_executed']);
                    $this->k->ringEffect($fresh->id);
                    break;
                default:
                    $fresh = $this->k->store->findEffect($claimed->id) ?? $claimed;
                    $fresh->reconcileAttempt++;
                    $fresh->status = EffectRecord::UNKNOWN;
                    $fresh->lockedBy = null;
                    $fresh->leaseUntil = null;
                    if ($policy->exhausted($fresh->reconcileAttempt)) {
                        $fresh->nextAttemptAt = null;
                        $this->k->store->updateEffect($fresh);
                        $this->k->incident($fresh->instanceId, IncidentRecord::EFFECT_UNKNOWN, "Reconciliation of {$fresh->class} could not tell whether it ran", ['key' => $fresh->key, 'attempts' => $fresh->reconcileAttempt], $fresh->id);
                    } else {
                        $fresh->nextAttemptAt = $policy->nextAttemptAt($this->k->now(), $fresh->reconcileAttempt);
                        $this->k->store->updateEffect($fresh);
                        $this->k->journal($fresh->instanceId, 'effect.reconcile_retry', ['key' => $fresh->key, 'attempt' => $fresh->reconcileAttempt]);
                    }
            }
        });
    }

    private function reconcilePolicy(EffectRecord $effect): Retry
    {
        $semantics = Steps::semantics($effect->class);

        return $semantics instanceof AtMostOnce ? $semantics->reconcileRetry() : Retry::reconcileDefault();
    }

    // Polls -------------------------------------------------------------------

    private function poll(EffectRecord $effect): void
    {
        $instance = $this->k->store->findInstance($effect->instanceId);
        $definition = $instance !== null ? $this->k->definitions->load($instance->definitionId) : null;
        $snapshot = $instance !== null ? Snapshot::fromArray($instance->snapshot) : null;
        $pollId = (string) ($effect->input['poll'] ?? '');
        $owner = $definition?->pollOwner($pollId);
        if ($instance === null || $definition === null || $snapshot === null || $owner === null || $instance->isFinished()
            || $snapshot->activation($owner->id) !== ($effect->input['activation'] ?? null) || ! $snapshot->isActive($owner->id)) {
            $effect->status = EffectRecord::CANCELLED;
            $effect->lockedBy = null;
            $this->k->store->updateEffect($effect);

            return;
        }

        $effect->input['context'] = $snapshot->context;
        $effect->input['contextClass'] = $snapshot->contextClass;

        try {
            $result = $this->call($effect, '__invoke');
        } catch (SimulatedCrash $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->k->tx(function () use ($effect, $e): void {
                $retry = Retry::fromArray($effect->retry);
                $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
                $fresh->attempt++;
                $fresh->status = EffectRecord::PENDING;
                $fresh->lockedBy = null;
                $fresh->leaseUntil = null;
                $fresh->error = ['code' => 'poll_error', 'message' => $e->getMessage()];
                if ($fresh->attempt === $retry->attempts) {
                    $this->k->incident($fresh->instanceId, IncidentRecord::POLL_FAILING, "Polling with {$fresh->class} keeps failing: {$e->getMessage()}", ['key' => $fresh->key], $fresh->id);
                }
                $fresh->nextAttemptAt = $retry->exhausted($fresh->attempt)
                    ? Duration::add($this->k->now(), $this->k->options->pollBackoffMax)
                    : $retry->nextAttemptAt($this->k->now(), $fresh->attempt);
                $this->k->store->updateEffect($fresh);
            });

            return;
        }

        $this->k->tx(function () use ($effect, $result, $definition, $snapshot, $pollId, $instance): void {
            $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
            if ($fresh->status === EffectRecord::CANCELLED) {
                return;
            }
            $fresh->runs++;
            $delivered = 0;
            foreach ($this->events($result) as $i => $item) {
                $key = 'poll:'.$fresh->id.':'.$fresh->runs.':'.$i;
                if ($item instanceof Publish || $item instanceof Send) {
                    $this->routeOne($item, $key);
                } else {
                    $event = Event::from($item);
                    $this->k->deliver($fresh->instanceId, $event->name, $event->payload, $key, Actor::system('poll'));
                }
                $delivered++;
            }
            if ($delivered > 0) {
                $this->k->journal($fresh->instanceId, 'poll.result', ['key' => $fresh->key, 'class' => $fresh->class, 'run' => $fresh->runs, 'events' => $delivered]);
            }
            $fresh->status = EffectRecord::PENDING;
            $fresh->attempt = 0;
            $fresh->error = null;
            $fresh->lockedBy = null;
            $fresh->leaseUntil = null;
            $fresh->nextAttemptAt = $this->k->interpreter->nextPoll($definition, $snapshot, $pollId, new StepContext($this->k->now(), $instance->id, $instance->seq));
            $this->k->store->updateEffect($fresh);
        });
    }

    // Compensations -----------------------------------------------------------

    private function compensation(EffectRecord $effect): void
    {
        $original = $effect->compensationOf !== null ? $this->k->store->findEffect($effect->compensationOf) : null;
        try {
            $this->call($effect, 'compensate', $original?->result);
        } catch (SimulatedCrash $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->k->tx(function () use ($effect, $e): void {
                $retry = Retry::fromArray($effect->retry);
                if ($retry->exhausted($effect->attempt)) {
                    $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
                    $fresh->status = EffectRecord::FAILED;
                    $fresh->error = ['code' => 'compensation_failed', 'message' => $e->getMessage()];
                    $fresh->lockedBy = null;
                    $this->k->store->updateEffect($fresh);
                    $this->k->incident($fresh->instanceId, IncidentRecord::COMPENSATION_FAILED, "Compensation of {$fresh->class} failed: {$e->getMessage()}", ['key' => $fresh->key], $fresh->id);

                    return;
                }
                $this->retryOrExhaust($effect, $e);
            });

            return;
        }

        $this->k->tx(function () use ($effect, $original): void {
            $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
            $fresh->status = EffectRecord::SUCCEEDED;
            $fresh->finishedAt = $this->k->now();
            $fresh->lockedBy = null;
            $fresh->leaseUntil = null;
            $this->k->store->updateEffect($fresh);
            if ($original !== null) {
                $original->status = EffectRecord::COMPENSATED;
                $this->k->store->updateEffect($original);
            }
            $this->k->journal($fresh->instanceId, 'effect.compensated', ['key' => $original->key ?? $fresh->key, 'class' => $fresh->class]);
            $this->continueCompensation($fresh);
        });
    }

    /**
     * Start the next compensation of the run, or report the run done.
     */
    public function continueCompensation(EffectRecord $finished): void
    {
        $run = $finished->input['run'] ?? null;
        $next = null;
        foreach ($this->k->store->effects($finished->instanceId, [EffectRecord::WAITING]) as $candidate) {
            if ($candidate->kind === EffectRecord::KIND_COMPENSATION && ($candidate->input['run'] ?? null) === $run
                && ($next === null || ($candidate->input['order'] ?? 0) < ($next->input['order'] ?? 0))) {
                $next = $candidate;
            }
        }
        if ($next !== null) {
            $next->status = EffectRecord::PENDING;
            $next->nextAttemptAt = $this->k->now();
            $this->k->store->updateEffect($next);
            $this->k->ringEffect($next->id);

            return;
        }
        $this->k->deliver($finished->instanceId, 'done.compensation', ['compensated' => $finished->input['total'] ?? null], 'compensation:'.$finished->instanceId.':'.$run);
    }

    // Helpers -----------------------------------------------------------------

    private function call(EffectRecord $effect, string $method, mixed $result = null): mixed
    {
        if (! class_exists($effect->class)) {
            throw new EffectFailed('handler_missing', "Effect handler {$effect->class} does not exist");
        }
        $handler = $this->k->container->get($effect->class);
        $reflection = new ReflectionMethod($handler, $method);

        $context = (array) ($effect->input['context'] ?? []);
        $contextClass = $effect->input['contextClass'] ?? null;
        $objects = [];
        if (is_string($contextClass) && class_exists($contextClass)) {
            $objects[$contextClass] = Hydrator::hydrate($contextClass, $context);
        }
        $objects[Effect::class] = new Effect(
            key: $effect->key,
            instanceId: $effect->instanceId,
            attempt: $effect->attempt,
            now: $this->k->now(),
            item: $effect->input['item'] ?? null,
            index: isset($effect->input['index']) ? (int) $effect->input['index'] : null,
            input: $effect->input['input'] ?? null,
        );
        $named = ['context' => $context, 'item' => $effect->input['item'] ?? null, 'input' => $effect->input['input'] ?? null, 'result' => $result];
        $extras = $method === 'compensate' ? [$result] : [];

        return $reflection->invokeArgs($handler, Arguments::resolve($reflection, $objects, $named, [], $extras));
    }

    /**
     * Route Publish/Send items of a result; return the rest as the output.
     */
    private function route(EffectRecord $effect, mixed $result): mixed
    {
        if ($result instanceof Publish || $result instanceof Send) {
            $this->routeOne($result, 'effect:'.$effect->id.':0');

            return null;
        }
        if (is_array($result) && array_is_list($result) && $result !== []) {
            $rest = [];
            $routed = false;
            foreach ($result as $i => $item) {
                if ($item instanceof Publish || $item instanceof Send) {
                    $this->routeOne($item, 'effect:'.$effect->id.':'.$i);
                    $routed = true;
                } else {
                    $rest[] = $item;
                }
            }
            if ($routed) {
                return $rest === [] ? null : Hydrator::dehydrate($rest);
            }
        }

        return Hydrator::dehydrate($result);
    }

    private function routeOne(Publish|Send $item, string $key): void
    {
        $event = Event::from($item->event, $item->payload);
        if ($item instanceof Publish) {
            $this->k->publish($event->name, $event->payload, $item->correlationKey, $key, Actor::system('effect'));
        } else {
            $this->k->deliver($item->instanceId, $event->name, $event->payload, $key, Actor::system('effect'));
        }
    }

    /**
     * @return list<mixed>
     */
    private function events(mixed $result): array
    {
        if ($result === null) {
            return [];
        }
        if (is_array($result) && array_is_list($result)) {
            return array_values(array_filter($result, fn ($r) => $r !== null));
        }

        return [$result];
    }

    private function promoteWaiting(EffectRecord $finished): void
    {
        if ($finished->kind !== EffectRecord::KIND_INVOKE || ! str_contains($finished->key, '#')) {
            return;
        }
        $prefix = substr($finished->key, 0, (int) strrpos($finished->key, '#') + 1);
        $next = null;
        foreach ($this->k->store->effects($finished->instanceId, [EffectRecord::WAITING]) as $candidate) {
            if (str_starts_with($candidate->key, $prefix) && ($next === null || ($candidate->input['index'] ?? 0) < ($next->input['index'] ?? 0))) {
                $next = $candidate;
            }
        }
        if ($next !== null) {
            $next->status = EffectRecord::PENDING;
            $next->nextAttemptAt = $this->k->now();
            $this->k->store->updateEffect($next);
            $this->k->ringEffect($next->id);
        }
    }

    private function code(Throwable $e): string
    {
        $pos = strrpos($e::class, '\\');

        return lcfirst($pos === false ? $e::class : substr($e::class, $pos + 1));
    }
}
