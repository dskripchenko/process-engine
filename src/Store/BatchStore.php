<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * Storage of batch invocations (ADR-014). Separate from Store so that a store
 * without it keeps working for processes without batches.
 */
interface BatchStore
{
    public function insertBatch(BatchRecord $record): void;

    public function findBatch(string $id): ?BatchRecord;

    /** Save under the version: false when another writer saved it since (`seq` moved). */
    public function updateBatch(BatchRecord $record, int $expectedSeq): bool;

    /**
     * @return list<BatchRecord> the batches of an instance, oldest first
     */
    public function batches(string $instanceId, ?string $status = null): array;

    /**
     * Add elements in this order (positions continue the batch's); keys
     * already in the batch are ignored. Returns how many were added.
     *
     * @param  list<string>  $keys
     */
    public function insertBatchItems(string $batchId, array $keys, int $firstPosition, DateTimeImmutable $now): int;

    /**
     * Elements in a status in position order, up to the limit.
     *
     * @return list<BatchItemRecord>
     */
    public function batchItems(string $batchId, ?string $status = null, int $limit = 100, ?int $afterPosition = null): array;

    /**
     * Elements by key.
     *
     * @param  list<string>  $keys
     * @return array<string, BatchItemRecord>
     */
    public function findBatchItems(string $batchId, array $keys): array;

    /**
     * @param  list<BatchItemRecord>  $items
     */
    public function updateBatchItems(array $items): void;

    /**
     * Move every element of the batch in one of the statuses to another.
     * Returns how many moved.
     *
     * @param  list<string>  $from
     */
    public function moveBatchItems(string $batchId, array $from, string $to, ?string $reason, DateTimeImmutable $now, bool $resetAttempts = false): int;

    /**
     * @return array<string, int> status => number of elements
     */
    public function countBatchItems(string $batchId): array;

    /** Remove the batches of an instance and their elements (archiving). */
    public function deleteBatches(string $instanceId): void;
}
