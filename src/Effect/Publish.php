<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

/**
 * Returned by an effect or a poll: deliver an event to whichever instances
 * subscribe to it under the correlation key (buffered if none does yet).
 */
final class Publish
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly string $correlationKey,
        public readonly object|string $event,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload  for string events
     */
    public static function to(string $correlationKey, object|string $event, array $payload = []): self
    {
        return new self($correlationKey, $event, $payload);
    }
}
