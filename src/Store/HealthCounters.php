<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * Counts for the health of the engine beyond what Store answers (ADR-017).
 * Optional: Engine::health() reports them when the store implements it.
 */
interface HealthCounters
{
    /** Effects whose outcome is unknown since before the moment (not reconciled yet). */
    public function countUnknownEffects(DateTimeImmutable $before): int;

    /** Events received before the moment and still waiting to be processed. */
    public function countStaleInbox(DateTimeImmutable $before): int;

    /** Effects due before the moment that no worker has taken up. */
    public function countStaleOutbox(DateTimeImmutable $before): int;
}
