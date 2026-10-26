<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * The doorbell: tells workers there is work. Called after the transaction
 * that created the work has committed. Losing a ring loses nothing: the
 * sweeper finds pending work in the store.
 */
interface Dispatcher
{
    public function instanceReady(string $instanceId): void;

    public function effectReady(string $effectId): void;
}
