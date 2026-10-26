<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * A published event nobody subscribed to yet, kept until its TTL ends.
 */
final class MessageRecord
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $actor
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly string $correlationKey,
        public readonly array $payload,
        public readonly string $key,
        public readonly ?array $actor,
        public readonly DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}
}
