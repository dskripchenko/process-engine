<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * One process instance as stored: the snapshot plus routing and locking data.
 */
final class InstanceRecord
{
    /**
     * @param  array<string, mixed>  $snapshot  Snapshot::toArray()
     * @param  array<string, mixed>  $parentLink  {invoke?, spawn?, activation?, index?, item?, onScopeExit}
     */
    public function __construct(
        public readonly string $id,
        public string $definitionId,
        public string $definitionName,
        public int $definitionVersion,
        public string $status,
        public array $snapshot,
        public int $seq,
        public ?string $parentId = null,
        public ?string $parentKey = null,
        public array $parentLink = [],
        public ?string $businessKey = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $lockedBy = null,
        public ?DateTimeImmutable $leaseUntil = null,
        public bool $hasIncidents = false,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?DateTimeImmutable $completedAt = null,
    ) {}

    /**
     * @return list<string>
     */
    public function activeStates(): array
    {
        return array_values(array_map('strval', (array) ($this->snapshot['active'] ?? [])));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }
}
