<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * Everything the runtime persists. The in-memory implementation ships with
 * the core; the Laravel layer implements it on the database.
 *
 * Contract highlights:
 * - `transaction()` is the unit of work of a step: all writes inside commit
 *   together or not at all; nested calls join the outer transaction.
 * - `updateInstance()` is the fencing point: it writes only when the stored
 *   `seq` equals `$expectedSeq` and returns false otherwise.
 * - `acquireLease()` / `claimEffect()` / `fireTimer()` are atomic
 *   compare-and-set operations committed on their own.
 * - Inserts keyed by an idempotency key return false (or null) on duplicates
 *   instead of throwing.
 */
interface Store
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;

    // Definitions -------------------------------------------------------------

    public function findDefinition(string $name, int $version): ?DefinitionRecord;

    public function findDefinitionById(string $id): ?DefinitionRecord;

    public function latestDefinition(string $name): ?DefinitionRecord;

    public function insertDefinition(DefinitionRecord $record): DefinitionRecord;

    /**
     * @return list<DefinitionRecord>
     */
    public function definitions(?string $name = null): array;

    public function deleteDefinition(string $id): void;

    // Instances ---------------------------------------------------------------

    /**
     * False when the id, or the business key within the process, is taken.
     */
    public function insertInstance(InstanceRecord $record): bool;

    public function findInstance(string $id): ?InstanceRecord;

    public function findInstanceByKey(string $definitionName, string $businessKey): ?InstanceRecord;

    public function findChild(string $parentId, string $parentKey): ?InstanceRecord;

    /**
     * @return list<InstanceRecord>
     */
    public function children(string $parentId): array;

    public function updateInstance(InstanceRecord $record, int $expectedSeq): bool;

    /** Flag set outside a step (incidents are raised by effect workers too). */
    public function setHasIncidents(string $id, bool $value): void;

    public function acquireLease(string $id, string $worker, DateTimeImmutable $until, DateTimeImmutable $now): bool;

    public function releaseLease(string $id, string $worker): void;

    /**
     * @return list<InstanceRecord>
     */
    public function queryInstances(InstanceQuery $query): array;

    public function countInstances(InstanceQuery $query): int;

    /**
     * Instance counts per active state of a definition version.
     *
     * @return array<string, int>
     */
    public function stateCounts(string $definitionId): array;

    // Inbox -------------------------------------------------------------------

    /**
     * Assigns `id` and `seq`. Returns false when the key is already in this instance's inbox.
     */
    public function insertInbox(InboxRecord $record): bool;

    public function findInboxByKey(string $instanceId, string $key): ?InboxRecord;

    public function nextInbox(string $instanceId): ?InboxRecord;

    /**
     * @return list<InboxRecord>
     */
    public function inbox(string $instanceId, ?string $status = null): array;

    public function updateInbox(InboxRecord $record): void;

    /**
     * Instances with pending inbox entries received before `$before`.
     *
     * @return list<string>
     */
    public function instancesWithPendingInbox(DateTimeImmutable $before, int $limit): array;

    // Effects -----------------------------------------------------------------

    public function insertEffect(EffectRecord $record): bool;

    public function findEffect(string $id): ?EffectRecord;

    public function findEffectByKey(string $key): ?EffectRecord;

    /**
     * Atomically move a due effect from `$from` to `$to`, setting the lease
     * (and incrementing `attempt` when `$countAttempt`). Null when it was not
     * in `$from`, not due, or taken by someone else.
     */
    public function claimEffect(string $id, string $from, string $to, string $worker, DateTimeImmutable $leaseUntil, DateTimeImmutable $now, bool $countAttempt): ?EffectRecord;

    public function updateEffect(EffectRecord $record): void;

    /**
     * Effects in the given statuses whose `nextAttemptAt` has come (null means not scheduled).
     *
     * @param  list<string>  $statuses
     * @return list<string>
     */
    public function dueEffects(array $statuses, DateTimeImmutable $now, int $limit): array;

    /**
     * Running or reconciling effects whose lease has expired.
     *
     * @return list<EffectRecord>
     */
    public function expiredEffects(DateTimeImmutable $now, int $limit): array;

    /**
     * @param  list<string>|null  $statuses
     * @return list<EffectRecord>
     */
    public function effects(string $instanceId, ?array $statuses = null): array;

    // Timers ------------------------------------------------------------------

    public function insertTimer(TimerRecord $record): bool;

    /**
     * Cancel pending timers whose key starts with the prefix.
     */
    public function cancelTimers(string $instanceId, string $prefix): int;

    /**
     * @return list<TimerRecord>
     */
    public function dueTimers(DateTimeImmutable $now, int $limit): array;

    /** Atomically pending → fired. */
    public function fireTimer(string $id): bool;

    /**
     * @return list<TimerRecord>
     */
    public function timers(string $instanceId, ?string $status = null): array;

    /**
     * Pending timers that should have fired before `$before`.
     *
     * @return list<TimerRecord>
     */
    public function overdueTimers(DateTimeImmutable $before, int $limit): array;

    // Subscriptions and buffered messages -------------------------------------

    public function insertSubscription(SubscriptionRecord $record): void;

    public function deleteSubscriptions(string $instanceId, string $prefix): void;

    /**
     * @return list<SubscriptionRecord>
     */
    public function subscribers(string $event, string $correlationKey): array;

    /**
     * @return list<SubscriptionRecord>
     */
    public function subscriptions(string $instanceId): array;

    /** False when a message with the same event, correlation key and key is buffered. */
    public function insertMessage(MessageRecord $record): bool;

    /**
     * Remove and return the buffered messages for the event and correlation key.
     *
     * @return list<MessageRecord>
     */
    public function takeMessages(string $event, string $correlationKey, DateTimeImmutable $now): array;

    public function purgeMessages(DateTimeImmutable $now): int;

    // Incidents ---------------------------------------------------------------

    public function insertIncident(IncidentRecord $record): void;

    public function updateIncident(IncidentRecord $record): void;

    public function findIncident(string $id): ?IncidentRecord;

    /**
     * @return list<IncidentRecord>
     */
    public function incidents(?string $instanceId = null, ?string $status = null, int $limit = 100): array;

    // Journal -----------------------------------------------------------------

    /**
     * Assigns `seq`: increasing within the instance (a global sequence is fine),
     * so `journal($id, $afterSeq)` pages in order.
     */
    public function appendJournal(JournalRecord $record): void;

    /**
     * @return list<JournalRecord>
     */
    public function journal(string $instanceId, int $afterSeq = 0, int $limit = 1000): array;

    // Archive -----------------------------------------------------------------

    /**
     * Finished instances (without open incidents or live children) completed before the moment.
     *
     * @return list<string>
     */
    public function archivable(DateTimeImmutable $completedBefore, int $limit): array;

    /**
     * Move an instance and everything it owns into the archive as one document.
     *
     * @param  array<string, mixed>  $document
     */
    public function archive(InstanceRecord $instance, array $document): void;

    /**
     * @return array<string, mixed>|null
     */
    public function findArchived(string $id): ?array;
}
