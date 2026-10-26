<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;
use Throwable;

/**
 * A Store in process memory: for tests, the synchronous engine and simple
 * scripts. Transactions snapshot the whole state and restore it on failure,
 * which is what crash simulations rely on.
 */
final class InMemoryStore implements Store
{
    /** @var array<string, DefinitionRecord> */
    private array $definitions = [];

    /** @var array<string, InstanceRecord> */
    private array $instances = [];

    /** @var array<string, array<int, InboxRecord>> */
    private array $inbox = [];

    /** @var array<string, int> */
    private array $inboxSeq = [];

    private int $inboxId = 0;

    /** @var array<string, EffectRecord> */
    private array $effects = [];

    /** @var array<string, string> */
    private array $effectKeys = [];

    /** @var array<string, TimerRecord> */
    private array $timers = [];

    /** @var list<SubscriptionRecord> */
    private array $subscriptions = [];

    /** @var array<string, MessageRecord> */
    private array $messages = [];

    /** @var array<string, IncidentRecord> */
    private array $incidents = [];

    /** @var array<string, list<JournalRecord>> */
    private array $journal = [];

    /** @var array<string, array<string, mixed>> */
    private array $archive = [];

    private int $depth = 0;

    private int $definitionId = 0;

    public function transaction(callable $callback): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $callback();
            } finally {
                $this->depth--;
            }
        }

        $saved = serialize($this->state());
        $this->depth = 1;
        try {
            $result = $callback();
            $this->depth = 0;

            return $result;
        } catch (Throwable $e) {
            $this->depth = 0;
            $this->restore(unserialize($saved));

            throw $e;
        }
    }

    // Definitions -------------------------------------------------------------

    public function findDefinition(string $name, int $version): ?DefinitionRecord
    {
        foreach ($this->definitions as $record) {
            if ($record->name === $name && $record->version === $version) {
                return $record;
            }
        }

        return null;
    }

    public function findDefinitionById(string $id): ?DefinitionRecord
    {
        return $this->definitions[$id] ?? null;
    }

    public function latestDefinition(string $name): ?DefinitionRecord
    {
        $latest = null;
        foreach ($this->definitions as $record) {
            if ($record->name === $name && ($latest === null || $record->version > $latest->version)) {
                $latest = $record;
            }
        }

        return $latest;
    }

    public function insertDefinition(DefinitionRecord $record): DefinitionRecord
    {
        $record->id = 'd'.++$this->definitionId;
        $this->definitions[$record->id] = $record;

        return $record;
    }

    public function definitions(?string $name = null): array
    {
        return array_values(array_filter($this->definitions, fn ($d) => $name === null || $d->name === $name));
    }

    public function deleteDefinition(string $id): void
    {
        unset($this->definitions[$id]);
    }

    // Instances ---------------------------------------------------------------

    public function insertInstance(InstanceRecord $record): bool
    {
        if (isset($this->instances[$record->id])) {
            return false;
        }
        $this->instances[$record->id] = clone $record;

        return true;
    }

    public function findInstance(string $id): ?InstanceRecord
    {
        return isset($this->instances[$id]) ? clone $this->instances[$id] : null;
    }

    public function findInstanceByKey(string $definitionName, string $businessKey): ?InstanceRecord
    {
        foreach ($this->instances as $record) {
            if ($record->definitionName === $definitionName && $record->businessKey === $businessKey) {
                return clone $record;
            }
        }

        return null;
    }

    public function findChild(string $parentId, string $parentKey): ?InstanceRecord
    {
        foreach ($this->instances as $record) {
            if ($record->parentId === $parentId && $record->parentKey === $parentKey) {
                return clone $record;
            }
        }

        return null;
    }

    public function children(string $parentId): array
    {
        return array_values(array_map(fn ($r) => clone $r, array_filter($this->instances, fn ($r) => $r->parentId === $parentId)));
    }

    public function updateInstance(InstanceRecord $record, int $expectedSeq): bool
    {
        $stored = $this->instances[$record->id] ?? null;
        if ($stored === null || $stored->seq !== $expectedSeq) {
            return false;
        }
        $this->instances[$record->id] = clone $record;

        return true;
    }

    public function setHasIncidents(string $id, bool $value): void
    {
        if (isset($this->instances[$id])) {
            $this->instances[$id]->hasIncidents = $value;
        }
    }

    public function acquireLease(string $id, string $worker, DateTimeImmutable $until, DateTimeImmutable $now): bool
    {
        $record = $this->instances[$id] ?? null;
        if ($record === null) {
            return false;
        }
        if ($record->leaseUntil !== null && $record->leaseUntil > $now && $record->lockedBy !== $worker) {
            return false;
        }
        $record->lockedBy = $worker;
        $record->leaseUntil = $until;

        return true;
    }

    public function releaseLease(string $id, string $worker): void
    {
        $record = $this->instances[$id] ?? null;
        if ($record !== null && $record->lockedBy === $worker) {
            $record->lockedBy = null;
            $record->leaseUntil = null;
        }
    }

    public function queryInstances(InstanceQuery $query): array
    {
        $matches = array_values(array_filter($this->instances, fn (InstanceRecord $r) => $this->matches($r, $query)));
        usort($matches, fn ($a, $b) => strcmp($b->id, $a->id));

        return array_map(fn ($r) => clone $r, array_slice($matches, $query->offset, $query->limit));
    }

    public function countInstances(InstanceQuery $query): int
    {
        return count(array_filter($this->instances, fn (InstanceRecord $r) => $this->matches($r, $query)));
    }

    public function stateCounts(string $definitionId): array
    {
        $counts = [];
        foreach ($this->instances as $record) {
            if ($record->definitionId !== $definitionId || $record->isFinished()) {
                continue;
            }
            foreach ($record->activeStates() as $state) {
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function matches(InstanceRecord $r, InstanceQuery $q): bool
    {
        return ($q->statuses === null || in_array($r->status, $q->statuses, true))
            && ($q->definitionName === null || $r->definitionName === $q->definitionName)
            && ($q->definitionVersion === null || $r->definitionVersion === $q->definitionVersion)
            && ($q->state === null || in_array($q->state, $r->activeStates(), true))
            && ($q->businessKey === null || $r->businessKey === $q->businessKey)
            && ($q->subjectType === null || $r->subjectType === $q->subjectType)
            && ($q->subjectId === null || $r->subjectId === $q->subjectId)
            && ($q->hasIncidents === null || $r->hasIncidents === $q->hasIncidents)
            && ($q->parentId === null || $r->parentId === $q->parentId);
    }

    // Inbox -------------------------------------------------------------------

    public function insertInbox(InboxRecord $record): bool
    {
        if ($this->findInboxByKey($record->instanceId, $record->key) !== null) {
            return false;
        }
        $record->id = ++$this->inboxId;
        $record->seq = $this->inboxSeq[$record->instanceId] = ($this->inboxSeq[$record->instanceId] ?? 0) + 1;
        $this->inbox[$record->instanceId][$record->id] = clone $record;

        return true;
    }

    public function findInboxByKey(string $instanceId, string $key): ?InboxRecord
    {
        foreach ($this->inbox[$instanceId] ?? [] as $record) {
            if ($record->key === $key) {
                return clone $record;
            }
        }

        return null;
    }

    public function nextInbox(string $instanceId): ?InboxRecord
    {
        $next = null;
        foreach ($this->inbox[$instanceId] ?? [] as $record) {
            if ($record->status === InboxRecord::PENDING && ($next === null || $record->seq < $next->seq)) {
                $next = $record;
            }
        }

        return $next !== null ? clone $next : null;
    }

    public function inbox(string $instanceId, ?string $status = null): array
    {
        $records = array_values(array_filter($this->inbox[$instanceId] ?? [], fn ($r) => $status === null || $r->status === $status));
        usort($records, fn ($a, $b) => $a->seq <=> $b->seq);

        return array_map(fn ($r) => clone $r, $records);
    }

    public function updateInbox(InboxRecord $record): void
    {
        if ($record->id !== null) {
            $this->inbox[$record->instanceId][$record->id] = clone $record;
        }
    }

    public function instancesWithPendingInbox(DateTimeImmutable $before, int $limit): array
    {
        $ids = [];
        foreach ($this->inbox as $instanceId => $records) {
            foreach ($records as $record) {
                if ($record->status === InboxRecord::PENDING && $record->receivedAt <= $before) {
                    $ids[] = $instanceId;
                    break;
                }
            }
        }

        return array_slice($ids, 0, $limit);
    }

    // Effects -----------------------------------------------------------------

    public function insertEffect(EffectRecord $record): bool
    {
        if (isset($this->effectKeys[$record->key])) {
            return false;
        }
        $this->effects[$record->id] = clone $record;
        $this->effectKeys[$record->key] = $record->id;

        return true;
    }

    public function findEffect(string $id): ?EffectRecord
    {
        return isset($this->effects[$id]) ? clone $this->effects[$id] : null;
    }

    public function findEffectByKey(string $key): ?EffectRecord
    {
        $id = $this->effectKeys[$key] ?? null;

        return $id !== null ? $this->findEffect($id) : null;
    }

    public function claimEffect(string $id, string $from, string $to, string $worker, DateTimeImmutable $leaseUntil, DateTimeImmutable $now, bool $countAttempt): ?EffectRecord
    {
        $record = $this->effects[$id] ?? null;
        if ($record === null || $record->status !== $from) {
            return null;
        }
        if ($record->nextAttemptAt !== null && $record->nextAttemptAt > $now) {
            return null;
        }
        $record->status = $to;
        $record->lockedBy = $worker;
        $record->leaseUntil = $leaseUntil;
        $record->startedAt = $now;
        if ($countAttempt) {
            $record->attempt++;
        }

        return clone $record;
    }

    public function updateEffect(EffectRecord $record): void
    {
        $this->effects[$record->id] = clone $record;
    }

    public function dueEffects(array $statuses, DateTimeImmutable $now, int $limit): array
    {
        $due = array_filter($this->effects, fn (EffectRecord $e) => in_array($e->status, $statuses, true) && $e->nextAttemptAt !== null && $e->nextAttemptAt <= $now);
        uasort($due, fn ($a, $b) => ($a->nextAttemptAt?->getTimestamp() ?? 0) <=> ($b->nextAttemptAt?->getTimestamp() ?? 0));

        return array_slice(array_keys($due), 0, $limit);
    }

    public function expiredEffects(DateTimeImmutable $now, int $limit): array
    {
        $expired = array_filter($this->effects, fn (EffectRecord $e) => in_array($e->status, [EffectRecord::RUNNING, EffectRecord::RECONCILING], true) && $e->leaseUntil !== null && $e->leaseUntil < $now);

        return array_map(fn ($e) => clone $e, array_slice(array_values($expired), 0, $limit));
    }

    public function effects(string $instanceId, ?array $statuses = null): array
    {
        $records = array_filter($this->effects, fn (EffectRecord $e) => $e->instanceId === $instanceId && ($statuses === null || in_array($e->status, $statuses, true)));

        return array_map(fn ($e) => clone $e, array_values($records));
    }

    // Timers ------------------------------------------------------------------

    public function insertTimer(TimerRecord $record): bool
    {
        foreach ($this->timers as $timer) {
            if ($timer->key === $record->key) {
                return false;
            }
        }
        $this->timers[$record->id] = clone $record;

        return true;
    }

    public function cancelTimers(string $instanceId, string $prefix): int
    {
        $n = 0;
        foreach ($this->timers as $timer) {
            if ($timer->instanceId === $instanceId && $timer->status === TimerRecord::PENDING && str_starts_with($timer->key, $prefix)) {
                $timer->status = TimerRecord::CANCELLED;
                $n++;
            }
        }

        return $n;
    }

    public function dueTimers(DateTimeImmutable $now, int $limit): array
    {
        $due = array_filter($this->timers, fn (TimerRecord $t) => $t->status === TimerRecord::PENDING && $t->fireAt <= $now);
        usort($due, fn ($a, $b) => $a->fireAt <=> $b->fireAt);

        return array_map(fn ($t) => clone $t, array_slice($due, 0, $limit));
    }

    public function fireTimer(string $id): bool
    {
        $timer = $this->timers[$id] ?? null;
        if ($timer === null || $timer->status !== TimerRecord::PENDING) {
            return false;
        }
        $timer->status = TimerRecord::FIRED;

        return true;
    }

    public function timers(string $instanceId, ?string $status = null): array
    {
        $records = array_filter($this->timers, fn (TimerRecord $t) => $t->instanceId === $instanceId && ($status === null || $t->status === $status));

        return array_map(fn ($t) => clone $t, array_values($records));
    }

    public function overdueTimers(DateTimeImmutable $before, int $limit): array
    {
        return $this->dueTimers($before, $limit);
    }

    // Subscriptions -----------------------------------------------------------

    public function insertSubscription(SubscriptionRecord $record): void
    {
        $this->subscriptions[] = $record;
    }

    public function deleteSubscriptions(string $instanceId, string $prefix): void
    {
        $this->subscriptions = array_values(array_filter(
            $this->subscriptions,
            fn (SubscriptionRecord $s) => ! ($s->instanceId === $instanceId && str_starts_with($s->key, $prefix)),
        ));
    }

    public function subscribers(string $event, string $correlationKey): array
    {
        return array_values(array_filter($this->subscriptions, fn ($s) => $s->event === $event && $s->correlationKey === $correlationKey));
    }

    public function subscriptions(string $instanceId): array
    {
        return array_values(array_filter($this->subscriptions, fn ($s) => $s->instanceId === $instanceId));
    }

    public function insertMessage(MessageRecord $record): bool
    {
        foreach ($this->messages as $message) {
            if ($message->event === $record->event && $message->correlationKey === $record->correlationKey && $message->key === $record->key) {
                return false;
            }
        }
        $this->messages[$record->id] = $record;

        return true;
    }

    public function takeMessages(string $event, string $correlationKey, DateTimeImmutable $now): array
    {
        $taken = [];
        foreach ($this->messages as $id => $message) {
            if ($message->event === $event && $message->correlationKey === $correlationKey && $message->expiresAt > $now) {
                $taken[] = $message;
                unset($this->messages[$id]);
            }
        }

        return $taken;
    }

    public function purgeMessages(DateTimeImmutable $now): int
    {
        $n = 0;
        foreach ($this->messages as $id => $message) {
            if ($message->expiresAt <= $now) {
                unset($this->messages[$id]);
                $n++;
            }
        }

        return $n;
    }

    // Incidents ---------------------------------------------------------------

    public function insertIncident(IncidentRecord $record): void
    {
        $this->incidents[$record->id] = clone $record;
    }

    public function updateIncident(IncidentRecord $record): void
    {
        $this->incidents[$record->id] = clone $record;
    }

    public function findIncident(string $id): ?IncidentRecord
    {
        return isset($this->incidents[$id]) ? clone $this->incidents[$id] : null;
    }

    public function incidents(?string $instanceId = null, ?string $status = null, int $limit = 100): array
    {
        $records = array_filter($this->incidents, fn (IncidentRecord $i) => ($instanceId === null || $i->instanceId === $instanceId) && ($status === null || $i->status === $status));

        return array_map(fn ($i) => clone $i, array_slice(array_values($records), 0, $limit));
    }

    // Journal -----------------------------------------------------------------

    public function appendJournal(JournalRecord $record): void
    {
        $record->seq = count($this->journal[$record->instanceId] ?? []) + 1;
        $this->journal[$record->instanceId][] = $record;
    }

    public function journal(string $instanceId, int $afterSeq = 0, int $limit = 1000): array
    {
        $records = array_filter($this->journal[$instanceId] ?? [], fn ($r) => $r->seq > $afterSeq);

        return array_slice(array_values($records), 0, $limit);
    }

    // Archive -----------------------------------------------------------------

    public function archivable(DateTimeImmutable $completedBefore, int $limit): array
    {
        $ids = [];
        foreach ($this->instances as $record) {
            if (! $record->isFinished() || $record->completedAt === null || $record->completedAt > $completedBefore) {
                continue;
            }
            if ($this->incidents($record->id, IncidentRecord::OPEN) !== []) {
                continue;
            }
            $liveChild = array_filter($this->instances, fn ($c) => $c->parentId === $record->id && ! $c->isFinished());
            if ($liveChild !== []) {
                continue;
            }
            $ids[] = $record->id;
        }

        return array_slice($ids, 0, $limit);
    }

    public function archive(InstanceRecord $instance, array $document): void
    {
        $id = $instance->id;
        $this->archive[$id] = $document;
        unset($this->instances[$id], $this->inbox[$id], $this->inboxSeq[$id], $this->journal[$id]);
        foreach ($this->effects as $effectId => $effect) {
            if ($effect->instanceId === $id) {
                unset($this->effects[$effectId], $this->effectKeys[$effect->key]);
            }
        }
        $this->timers = array_filter($this->timers, fn ($t) => $t->instanceId !== $id);
        $this->incidents = array_filter($this->incidents, fn ($i) => $i->instanceId !== $id);
        $this->subscriptions = array_values(array_filter($this->subscriptions, fn ($s) => $s->instanceId !== $id));
    }

    public function findArchived(string $id): ?array
    {
        return $this->archive[$id] ?? null;
    }

    // State snapshot for transactions -----------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function restore(array $state): void
    {
        foreach ($state as $key => $value) {
            if ($key !== 'depth') {
                $this->{$key} = $value;
            }
        }
    }
}
