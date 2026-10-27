<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * One element of a batch.
 */
final class BatchItemRecord
{
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public function __construct(
        public readonly string $batchId,
        public readonly string $key,
        public readonly int $position,
        public string $status = self::PENDING,
        public int $attempts = 0,
        public ?string $reason = null,
        public ?string $effectId = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}
