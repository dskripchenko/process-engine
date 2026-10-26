<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

/**
 * Returned by an effect or a poll: deliver an event to a known instance.
 */
final class Send
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly string $instanceId,
        public readonly object|string $event,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload  for string events
     */
    public static function to(string $instanceId, object|string $event, array $payload = []): self
    {
        return new self($instanceId, $event, $payload);
    }
}
