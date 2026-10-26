<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Support\Duration;
use InvalidArgumentException;

/**
 * A retry policy as data: how many attempts, how long to wait between them,
 * how long one attempt may take and what happens when attempts run out.
 */
final class Retry
{
    public const ON_EXHAUSTED_ERROR = 'error';

    public const ON_EXHAUSTED_INCIDENT = 'incident';

    public const ON_EXHAUSTED_FAIL = 'fail';

    public function __construct(
        public readonly int $attempts = 5,
        public readonly string $initialInterval = '10 seconds',
        public readonly float $backoff = 2.0,
        public readonly string $maxInterval = '1 hour',
        public readonly bool $jitter = true,
        public readonly string $attemptTimeout = '2 minutes',
        public readonly string $onExhausted = self::ON_EXHAUSTED_ERROR,
    ) {
        if ($attempts < 1) {
            throw new InvalidArgumentException('A retry policy needs at least one attempt');
        }
        if (! in_array($onExhausted, [self::ON_EXHAUSTED_ERROR, self::ON_EXHAUSTED_INCIDENT, self::ON_EXHAUSTED_FAIL], true)) {
            throw new InvalidArgumentException("Unknown onExhausted `{$onExhausted}`: error, incident or fail");
        }
        foreach ([$initialInterval, $maxInterval, $attemptTimeout] as $duration) {
            if (! Duration::isValid($duration)) {
                throw new InvalidArgumentException("Invalid duration `{$duration}` in a retry policy");
            }
        }
    }

    public static function idempotentDefault(): self
    {
        return new self;
    }

    public static function atMostOnceDefault(): self
    {
        return new self(attempts: 3);
    }

    public static function reconcileDefault(): self
    {
        return new self(attempts: 10, initialInterval: '1 minute', maxInterval: '6 hours', onExhausted: self::ON_EXHAUSTED_INCIDENT);
    }

    /** Compensations are retried for long, then wait for an operator. */
    public static function compensationDefault(): self
    {
        return new self(attempts: 20, initialInterval: '10 seconds', maxInterval: '1 hour', onExhausted: self::ON_EXHAUSTED_INCIDENT);
    }

    public static function forever(): self
    {
        return new self(attempts: PHP_INT_MAX, initialInterval: '10 seconds', maxInterval: '1 hour', onExhausted: self::ON_EXHAUSTED_INCIDENT);
    }

    /**
     * When the attempt after `$attempt` (1-based, the one that just failed) is due.
     */
    public function nextAttemptAt(DateTimeImmutable $now, int $attempt): DateTimeImmutable
    {
        $initial = Duration::seconds($this->initialInterval);
        $max = Duration::seconds($this->maxInterval);
        $delay = (int) min($max, $initial * ($this->backoff ** max(0, $attempt - 1)));
        if ($this->jitter && $delay > 1) {
            $delay = random_int((int) ceil($delay / 2), $delay);
        }

        return $now->modify("+{$delay} seconds");
    }

    public function exhausted(int $attempt): bool
    {
        return $attempt >= $this->attempts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempts' => $this->attempts,
            'initialInterval' => $this->initialInterval,
            'backoff' => $this->backoff,
            'maxInterval' => $this->maxInterval,
            'jitter' => $this->jitter,
            'attemptTimeout' => $this->attemptTimeout,
            'onExhausted' => $this->onExhausted,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            attempts: (int) ($data['attempts'] ?? 5),
            initialInterval: (string) ($data['initialInterval'] ?? '10 seconds'),
            backoff: (float) ($data['backoff'] ?? 2.0),
            maxInterval: (string) ($data['maxInterval'] ?? '1 hour'),
            jitter: (bool) ($data['jitter'] ?? true),
            attemptTimeout: (string) ($data['attemptTimeout'] ?? '2 minutes'),
            onExhausted: (string) ($data['onExhausted'] ?? self::ON_EXHAUSTED_ERROR),
        );
    }
}
