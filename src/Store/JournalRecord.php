<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * One line of an instance's history.
 */
final class JournalRecord
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $actor
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly string $type,
        public readonly array $data,
        public readonly DateTimeImmutable $at,
        public readonly ?array $actor = null,
        public readonly ?string $reason = null,
        public int $seq = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seq' => $this->seq,
            'type' => $this->type,
            'data' => $this->data,
            'actor' => $this->actor,
            'reason' => $this->reason,
            'at' => $this->at->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
