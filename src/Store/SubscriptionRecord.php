<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

/**
 * An instance listening for an event published under a correlation key.
 */
final class SubscriptionRecord
{
    public function __construct(
        public readonly string $instanceId,
        public readonly string $key,
        public readonly string $event,
        public readonly string $correlationKey,
        public readonly string $activation,
    ) {}
}
