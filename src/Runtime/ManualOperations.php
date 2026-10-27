<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Interpreter\StepResult;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Dskripchenko\ProcessEngine\Support\JsonPatch;
use InvalidArgumentException;

/**
 * Operator actions. They arrive through the inbox (`manual.*` events), so
 * they are serialized with ordinary events under the instance lease and land
 * in the journal with their actor and reason.
 *
 * @internal
 */
final class ManualOperations
{
    public const OPERATIONS = [
        'transition', 'patch', 'suspend', 'resume', 'cancel', 'fail',
        'retry_effect', 'resolve_effect', 'skip_effect', 'resolve_incident', 'redeliver', 'migrate',
        'retry_batch', 'skip_batch',
    ];

    private ?Migrator $migrator = null;

    public function __construct(
        private readonly Kernel $k,
        private readonly Steps $steps,
        private readonly EffectExecutor $effects,
    ) {}

    public function setMigrator(Migrator $migrator): void
    {
        $this->migrator = $migrator;
    }

    public function handle(InstanceRecord $instance, InboxRecord $inbox): void
    {
        $op = substr($inbox->name, strlen('manual.'));
        $p = $inbox->payload;
        $actor = Actor::fromArray($inbox->actor) ?? Actor::system();
        $reason = isset($p['reason']) ? (string) $p['reason'] : null;

        try {
            $outcome = match ($op) {
                'transition' => $this->transition($instance, $p, $actor, $reason),
                'patch' => $this->patch($instance, $p, $actor, $reason),
                'suspend' => $this->setStatus($instance, Snapshot::SUSPENDED, $actor, $reason),
                'resume' => $this->setStatus($instance, Snapshot::RUNNING, $actor, $reason),
                'cancel' => $this->cancel($instance, (bool) ($p['compensate'] ?? false), $actor, $reason),
                'fail' => $this->fail($instance, $actor, $reason ?? (string) ($p['reason'] ?? 'failed')),
                'retry_effect' => $this->retryEffect($instance, (string) ($p['effect'] ?? ''), (bool) ($p['confirmUnsafe'] ?? false)),
                'resolve_effect' => $this->resolveEffect($instance, (string) ($p['effect'] ?? ''), $p),
                'skip_effect' => $this->resolveEffect($instance, (string) ($p['effect'] ?? ''), ['outcome' => 'succeeded', 'result' => $p['result'] ?? null, 'skipped' => true]),
                'resolve_incident' => $this->resolveIncident($instance, (string) ($p['incident'] ?? ''), (string) ($p['resolution'] ?? 'resolved'), $actor),
                'redeliver' => $this->redeliver($instance, (int) ($p['inbox'] ?? 0)),
                'retry_batch' => $this->k->batches()->retryItems($instance->id, (string) ($p['batch'] ?? ''), array_values(array_map('strval', (array) ($p['statuses'] ?? ['failed', 'deferred'])))),
                'skip_batch' => $this->k->batches()->skip($instance->id, (string) ($p['batch'] ?? ''), $reason),
                'migrate' => $this->migrator?->apply($instance, $p, $actor, $reason) ?? throw new InvalidArgumentException('Migrations are not configured'),
                default => throw new InvalidArgumentException("Unknown operation `{$op}`"),
            };
            $status = InboxRecord::PROCESSED;
            $note = $outcome;
        } catch (SimulatedCrash|ConcurrencyConflict $e) {
            throw $e;
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $status = InboxRecord::FAILED;
            $note = $e->getMessage();
            $this->k->journal($instance->id, 'manual.rejected', ['operation' => $op, 'params' => $p, 'error' => $e->getMessage()], $actor, $reason);
        }

        $inbox->status = $status;
        $inbox->reason = $note;
        $inbox->processedAt = $this->k->now();
        $this->k->store->updateInbox($inbox);
        if ($status === InboxRecord::PROCESSED) {
            $this->k->journal($instance->id, 'manual.'.$op, ['params' => $p, 'note' => $note], $actor, $reason);
        }
    }

    /**
     * The cancellation that waited for compensation to finish.
     */
    public function finishCancel(InstanceRecord $instance, InboxRecord $inbox): void
    {
        $snapshot = Snapshot::fromArray($instance->snapshot);
        $flag = (array) ($snapshot->flags['cancel'] ?? []);
        $this->steps->cancelTree($instance, Actor::fromArray(isset($flag['actor']) && is_array($flag['actor']) ? $flag['actor'] : null), isset($flag['reason']) ? (string) $flag['reason'] : 'cancelled after compensation', true);
        $inbox->status = InboxRecord::PROCESSED;
        $inbox->processedAt = $this->k->now();
        $this->k->store->updateInbox($inbox);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function transition(InstanceRecord $instance, array $p, Actor $actor, ?string $reason): string
    {
        $this->assertRunning($instance);
        $states = array_values(array_map('strval', (array) ($p['states'] ?? [])));
        if ($states === []) {
            throw new InvalidArgumentException('Name the target states');
        }
        $definition = $this->k->definitions->load($instance->definitionId);
        $snapshot = Snapshot::fromArray($instance->snapshot);
        $result = $this->k->interpreter->transitionTo($definition, $snapshot, $states, $this->ctx($instance), (bool) ($p['runExit'] ?? true), (bool) ($p['runEntry'] ?? true));
        $this->steps->commit($instance, $definition, $result, null, $actor, $snapshot->context, $reason);

        return 'moved to '.implode(', ', $states);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function patch(InstanceRecord $instance, array $p, Actor $actor, ?string $reason): string
    {
        $this->assertNotFinished($instance);
        $snapshot = Snapshot::fromArray($instance->snapshot);
        $before = $snapshot->context;
        /** @var list<array<string, mixed>> $patch */
        $patch = array_values((array) ($p['patch'] ?? []));
        $snapshot->context = JsonPatch::apply($before, $patch);
        $this->commitSnapshot($instance, $snapshot, $actor, $before, $reason);

        return count($patch).' operations';
    }

    private function setStatus(InstanceRecord $instance, string $status, Actor $actor, ?string $reason): string
    {
        $this->assertNotFinished($instance);
        $snapshot = Snapshot::fromArray($instance->snapshot);
        if ($snapshot->status === $status) {
            return "already {$status}";
        }
        $snapshot->status = $status;
        $this->commitSnapshot($instance, $snapshot, $actor, $snapshot->context, $reason);
        if ($status === Snapshot::RUNNING) {
            $this->k->ringInstance($instance->id);
            $this->k->batches()->resume($instance->id);
        }

        return $status;
    }

    private function cancel(InstanceRecord $instance, bool $compensate, Actor $actor, ?string $reason): string
    {
        $this->assertNotFinished($instance);
        if (! $compensate) {
            $this->steps->cancelTree($instance, $actor, $reason, true);

            return 'cancelled';
        }
        $snapshot = Snapshot::fromArray($instance->snapshot);
        $snapshot->flags['cancel'] = ['actor' => $actor->toArray(), 'reason' => $reason];
        $snapshot->status = Snapshot::RUNNING;
        $this->commitSnapshot($instance, $snapshot, $actor, $snapshot->context, $reason);
        $this->steps->compensate($instance, null);

        return 'compensating before cancellation';
    }

    private function fail(InstanceRecord $instance, Actor $actor, string $reason): string
    {
        $this->assertNotFinished($instance);
        $definition = $this->k->definitions->load($instance->definitionId);
        $snapshot = Snapshot::fromArray($instance->snapshot);
        $result = $this->k->interpreter->cancel($definition, $snapshot, $this->ctx($instance));
        $result->snapshot->status = Snapshot::FAILED;
        $this->steps->commit($instance, $definition, $result, null, $actor, $snapshot->context, $reason);
        foreach ($this->k->store->children($instance->id) as $child) {
            $this->steps->cancelTree($child, $actor, 'parent failed', false);
        }
        $this->steps->notifyParent($instance, 'failed', null, null);

        return 'failed';
    }

    private function retryEffect(InstanceRecord $instance, string $effectId, bool $confirmUnsafe): string
    {
        $effect = $this->effect($instance, $effectId);
        if (! in_array($effect->status, [EffectRecord::FAILED, EffectRecord::UNKNOWN, EffectRecord::CANCELLED], true)) {
            throw new InvalidArgumentException("Effect {$effectId} is {$effect->status}: only failed, unknown or cancelled effects are retried");
        }
        if ($effect->status === EffectRecord::UNKNOWN && $effect->isAtMostOnce() && ! $confirmUnsafe) {
            throw new InvalidArgumentException('The outcome is unknown and the effect must not run twice: pass confirmUnsafe to take responsibility');
        }
        $effect->status = EffectRecord::PENDING;
        $effect->attempt = 0;
        $effect->reconcileAttempt = 0;
        $effect->nextAttemptAt = $this->k->now();
        $effect->error = null;
        $this->k->store->updateEffect($effect);
        $this->k->ringEffect($effect->id);

        return 'effect requeued';
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function resolveEffect(InstanceRecord $instance, string $effectId, array $p): string
    {
        $effect = $this->effect($instance, $effectId);
        if (! in_array($effect->status, [EffectRecord::UNKNOWN, EffectRecord::FAILED, EffectRecord::PENDING, EffectRecord::RUNNING, EffectRecord::RECONCILING], true)) {
            throw new InvalidArgumentException("Effect {$effectId} is {$effect->status} and cannot be resolved");
        }
        $effect->status = EffectRecord::RUNNING;
        $this->k->store->updateEffect($effect);
        if (($p['outcome'] ?? 'succeeded') === 'failed') {
            $this->effects->fail($effect, (string) ($p['code'] ?? 'resolved_failed'), (string) ($p['message'] ?? 'resolved as failed by an operator'));

            return 'resolved as failed';
        }
        $this->effects->succeed($effect, $p['result'] ?? null, ($p['skipped'] ?? false) ? 'effect.skipped' : 'effect.resolved');

        return ($p['skipped'] ?? false) ? 'skipped' : 'resolved as succeeded';
    }

    private function resolveIncident(InstanceRecord $instance, string $incidentId, string $resolution, Actor $actor): string
    {
        $incident = $this->k->store->findIncident($incidentId);
        if ($incident === null || $incident->instanceId !== $instance->id) {
            throw new InvalidArgumentException("Incident {$incidentId} does not belong to the instance");
        }
        if ($incident->status === IncidentRecord::RESOLVED) {
            return 'already resolved';
        }
        $incident->status = IncidentRecord::RESOLVED;
        $incident->resolution = $resolution;
        $incident->resolvedBy = $actor->toArray();
        $incident->resolvedAt = $this->k->now();
        $this->k->store->updateIncident($incident);
        if ($this->k->store->incidents($instance->id, IncidentRecord::OPEN) === []) {
            $this->k->store->setHasIncidents($instance->id, false);
        }
        $this->k->signal('incident.resolved', $instance->id, ['id' => $incidentId]);

        return $resolution;
    }

    private function redeliver(InstanceRecord $instance, int $inboxId): string
    {
        foreach ($this->k->store->inbox($instance->id, InboxRecord::FAILED) as $failed) {
            if ($failed->id === $inboxId) {
                $failed->status = InboxRecord::PENDING;
                $failed->reason = 'redelivered';
                $this->k->store->updateInbox($failed);

                return 'redelivered '.$failed->name;
            }
        }

        throw new InvalidArgumentException("No failed inbox entry {$inboxId}");
    }

    // Helpers -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $before
     */
    private function commitSnapshot(InstanceRecord $instance, Snapshot $snapshot, Actor $actor, array $before, ?string $reason): void
    {
        $definition = $this->k->definitions->load($instance->definitionId);
        $this->steps->commit($instance, $definition, new StepResult(StepResult::TRANSITIONED, $snapshot), null, $actor, $before, $reason);
    }

    private function effect(InstanceRecord $instance, string $id): EffectRecord
    {
        $effect = $this->k->store->findEffect($id);
        if ($effect === null || $effect->instanceId !== $instance->id) {
            throw new InvalidArgumentException("Effect {$id} does not belong to the instance");
        }

        return $effect;
    }

    private function ctx(InstanceRecord $instance): StepContext
    {
        return new StepContext($this->k->now(), $instance->id, $instance->seq + 1);
    }

    private function assertRunning(InstanceRecord $instance): void
    {
        $this->assertNotFinished($instance);
    }

    private function assertNotFinished(InstanceRecord $instance): void
    {
        if ($instance->isFinished()) {
            throw new InvalidArgumentException("The instance is {$instance->status}");
        }
    }
}
