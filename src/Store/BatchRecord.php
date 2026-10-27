<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * A batch invocation (ADR-014): where its source stands, how many chunks run
 * and what came of its elements. Versioned by `seq`: concurrent chunk
 * completions serialize on it.
 */
final class BatchRecord
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Statuses of elements counted in `counters`: the final ones. */
    public const COUNTED = ['done', 'not_found', 'failed', 'deferred', 'skipped'];

    /**
     * @param  array<string, mixed>  $context  the instance context when the batch started
     * @param  array<string, mixed>  $retry  the retry policy of a chunk
     * @param  array<string, int>  $counters  final element statuses => number
     * @param  array<string, mixed>|null  $error  {code, message}
     */
    public function __construct(
        public readonly string $id,
        public readonly string $instanceId,
        public readonly string $key,
        public readonly string $invoke,
        public readonly string $state,
        public readonly string $activation,
        public readonly string $handler,
        public readonly string $source,
        public readonly int $chunk,
        public readonly int $concurrency,
        public readonly int $itemAttempts,
        public readonly array $retry,
        public readonly array $context,
        public readonly ?string $contextClass,
        public string $status = self::RUNNING,
        public ?string $cursor = null,
        public bool $exhausted = false,
        public int $running = 0,
        public int $chunks = 0,
        public int $seen = 0,
        public ?int $total = null,
        public array $counters = [],
        public ?array $error = null,
        public int $seq = 0,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $finishedAt = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /**
     * The counters with every status present, plus `total` (known or seen so far).
     *
     * @return array<string, int|bool|null>
     */
    public function summary(): array
    {
        $out = [];
        foreach (self::COUNTED as $status) {
            $out[$status] = $this->counters[$status] ?? 0;
        }
        $out['total'] = $this->exhausted ? $this->seen : max($this->total ?? 0, $this->seen);

        return $out;
    }
}
