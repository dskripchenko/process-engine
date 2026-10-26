<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Interpreter\MicrostepLimitExceeded;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Interpreter\StepResult;
use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Dskripchenko\ProcessEngine\Support\Duration;
use Throwable;

/**
 * Steps one instance: takes its lease, works off its inbox in order, one
 * transaction per event, and gives the lease back.
 *
 * @internal
 */
final class InstanceRunner
{
    public function __construct(
        private readonly Kernel $k,
        private readonly Steps $steps,
        private readonly ManualOperations $manual,
    ) {}

    /**
     * False when another worker holds the instance.
     */
    public function run(string $instanceId): bool
    {
        $now = $this->k->now();
        if (! $this->k->store->acquireLease($instanceId, $this->k->worker, Duration::add($now, $this->k->options->leaseTtl), $now)) {
            return false;
        }
        try {
            for ($i = 0; $i < $this->k->options->eventsPerRun; $i++) {
                $inbox = $this->next($instanceId);
                if ($inbox === null) {
                    break;
                }
                $continue = $this->k->tx(fn () => $this->process($instanceId, $inbox), 'runner.after_commit');
                if (! $continue) {
                    break;
                }
            }
        } catch (SimulatedCrash $crash) {
            // A killed worker keeps its lease until it expires.
            throw $crash;
        } catch (Throwable $e) {
            $this->k->store->releaseLease($instanceId, $this->k->worker);

            throw $e;
        }
        $this->k->store->releaseLease($instanceId, $this->k->worker);
        if ($this->k->store->nextInbox($instanceId) !== null && $this->k->store->findInstance($instanceId)?->status !== Snapshot::SUSPENDED) {
            $this->k->ringInstance($instanceId);
        }

        return true;
    }

    /**
     * The next event in order; while suspended only operator actions jump the
     * queue (a resume must not wait behind the events it is meant to release).
     */
    private function next(string $instanceId): ?InboxRecord
    {
        $instance = $this->k->store->findInstance($instanceId);
        if ($instance !== null && $instance->status === Snapshot::SUSPENDED) {
            foreach ($this->k->store->inbox($instanceId, InboxRecord::PENDING) as $pending) {
                if (str_starts_with($pending->name, 'manual.')) {
                    return $pending;
                }
            }

            return null;
        }

        return $this->k->store->nextInbox($instanceId);
    }

    private function process(string $instanceId, InboxRecord $inbox): bool
    {
        $instance = $this->k->store->findInstance($instanceId);
        if ($instance === null) {
            return false;
        }
        $actor = Actor::fromArray($inbox->actor);

        if (str_starts_with($inbox->name, 'manual.')) {
            $this->manual->handle($instance, $inbox);

            return true;
        }
        if ($instance->isFinished()) {
            $this->finishInbox($inbox, InboxRecord::IGNORED, "the instance is {$instance->status}");
            $this->k->journal($instanceId, 'event.ignored', ['event' => ['name' => $inbox->name, 'payload' => $inbox->payload], 'reason' => "the instance is {$instance->status}"], $actor);

            return true;
        }
        if ($instance->status === Snapshot::SUSPENDED) {
            return false;
        }

        $definition = $this->k->definitions->load($instance->definitionId);
        $event = new Event($inbox->name, $inbox->payload);
        $snapshot = Snapshot::fromArray($instance->snapshot);
        if ($inbox->name === 'done.compensation' && isset($snapshot->flags['cancel'])) {
            $this->manual->finishCancel($instance, $inbox);

            return true;
        }
        $before = $snapshot->context;
        $ctx = new StepContext($this->k->now(), $instanceId, $instance->seq + 1, [], $this->aliveSpawned($instance));

        try {
            $result = $this->k->interpreter->step($definition, $snapshot, $event, $ctx);
        } catch (MicrostepLimitExceeded $e) {
            $this->fail($instance, $inbox, IncidentRecord::MICROSTEP_LIMIT, $e);

            return true;
        } catch (SimulatedCrash $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fail($instance, $inbox, IncidentRecord::STEP_ERROR, $e);

            return true;
        }

        $this->k->faults->hit('runner.before_commit');

        try {
            if ($result->changed()) {
                $this->steps->commit($instance, $definition, $result, $event, $actor, $before);
            } else {
                $this->steps->journalStep($instance, $result, $event, $actor, $before);
            }
        } catch (ContextTooLarge $e) {
            $this->fail($instance, $inbox, IncidentRecord::CONTEXT_TOO_LARGE, $e);

            return true;
        }

        $status = match ($result->outcome) {
            StepResult::IGNORED => InboxRecord::IGNORED,
            StepResult::DEFERRED => InboxRecord::DEFERRED,
            StepResult::DISCARDED => InboxRecord::DISCARDED,
            default => InboxRecord::PROCESSED,
        };
        $this->finishInbox($inbox, $status, $result->reason);

        if ($result->outcome === StepResult::IGNORED) {
            $this->k->signal('event.ignored', $instanceId, ['event' => $inbox->name]);
            if (str_starts_with($inbox->name, 'error.invoke.') || str_starts_with($inbox->name, 'error.spawn.')) {
                $this->k->incident($instanceId, IncidentRecord::EFFECT_FAILED, 'Unhandled failure: '.($inbox->payload['message'] ?? $inbox->payload['code'] ?? $inbox->name), ['event' => $inbox->name, 'payload' => $inbox->payload]);
            } elseif (str_starts_with($inbox->name, 'unknown.invoke.') && ($inbox->payload['reconcilable'] ?? false) !== true) {
                $this->k->incident($instanceId, IncidentRecord::EFFECT_UNKNOWN, 'The outcome of an at-most-once effect is unknown and it cannot be reconciled', ['event' => $inbox->name, 'payload' => $inbox->payload], isset($inbox->payload['effect']) ? (string) $inbox->payload['effect'] : null);
            }
        }
        if ($result->outcome === StepResult::TRANSITIONED) {
            $this->requeueDeferred($instanceId);
        }

        return true;
    }

    private function finishInbox(InboxRecord $inbox, string $status, ?string $reason): void
    {
        $inbox->status = $status;
        $inbox->reason = $reason;
        $inbox->processedAt = $this->k->now();
        $this->k->store->updateInbox($inbox);
    }

    private function fail(InstanceRecord $instance, InboxRecord $inbox, string $type, Throwable $e): void
    {
        $this->finishInbox($inbox, InboxRecord::FAILED, $e->getMessage());
        $this->k->incident($instance->id, $type, $e->getMessage(), [
            'event' => ['name' => $inbox->name, 'payload' => $inbox->payload],
            'inbox' => $inbox->id,
            'exception' => $e::class,
        ]);
    }

    private function requeueDeferred(string $instanceId): void
    {
        $now = $this->k->now();
        foreach ($this->k->store->inbox($instanceId, InboxRecord::DEFERRED) as $deferred) {
            if (Duration::add($deferred->receivedAt, $this->k->options->deferTtl) < $now) {
                $this->finishInbox($deferred, InboxRecord::IGNORED, 'deferred too long');

                continue;
            }
            $deferred->status = InboxRecord::PENDING;
            $this->k->store->updateInbox($deferred);
        }
    }

    private function aliveSpawned(InstanceRecord $instance): int
    {
        $n = 0;
        foreach ($this->k->store->children($instance->id) as $child) {
            if (isset($child->parentLink['spawn']) && ! $child->isFinished()) {
                $n++;
            }
        }

        return $n;
    }
}
