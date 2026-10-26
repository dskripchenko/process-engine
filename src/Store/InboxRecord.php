<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * An incoming event waiting for (or done with) its step.
 */
final class InboxRecord
{
    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const DEFERRED = 'deferred';

    public const DISCARDED = 'discarded';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $actor
     */
    public function __construct(
        public ?int $id,
        public readonly string $instanceId,
        public readonly string $name,
        public readonly array $payload,
        public readonly string $key,
        public readonly ?array $actor,
        public string $status,
        public readonly DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $processedAt = null,
        public ?string $reason = null,
        public int $seq = 0,
    ) {}
}
