<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Tunables of the runtime.
 */
final class EngineOptions
{
    public function __construct(
        /** How long a worker owns an instance while stepping it. */
        public readonly string $leaseTtl = '2 minutes',
        /** Events processed per run of an instance before yielding. */
        public readonly int $eventsPerRun = 100,
        /** Largest context, in bytes of JSON. */
        public readonly int $maxContextBytes = 262144,
        /** How long a published event waits for a subscriber. */
        public readonly string $messageTtl = '7 days',
        /** How long deferred events wait for a state that accepts them. */
        public readonly string $deferTtl = '30 days',
        /** Pending inbox entries older than this are re-rung by the sweeper. */
        public readonly string $sweepAfter = '30 seconds',
        /** Finished instances older than this are archived. */
        public readonly string $archiveAfter = '30 days',
        /** A pending timer later than this is reported overdue. */
        public readonly string $overdueAfter = '5 minutes',
        /** Interval of failing polls after their retry policy is exhausted. */
        public readonly string $pollBackoffMax = '1 hour',
        /** Signs task tokens handed to external parties. */
        public readonly string $secret = 'change-me',
    ) {}
}
