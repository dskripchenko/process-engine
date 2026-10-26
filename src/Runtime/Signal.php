<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Something observable happened: hand it to metrics, alerts, logs.
 *
 * Types: instance.started, instance.completed, instance.failed,
 * instance.cancelled, step.committed, event.ignored, effect.succeeded,
 * effect.failed, effect.unknown, incident.opened, incident.resolved,
 * timer.overdue.
 */
final class Signal
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $instanceId,
        public readonly array $data = [],
    ) {}
}
