<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

use Dskripchenko\ProcessEngine\Support\EventName;
use Dskripchenko\ProcessEngine\Support\Hydrator;
use InvalidArgumentException;

/**
 * An event as the engine stores and routes it: a name and a JSON-ready payload.
 * Built from an event object (its public properties) or a name and an array.
 */
final class Event
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $name,
        public readonly array $payload = [],
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('An event needs a name');
        }
    }

    /**
     * @param  array<string, mixed>  $payload  for a named event
     */
    public static function from(object|string $event, array $payload = []): self
    {
        if ($event instanceof self) {
            return $event;
        }
        if (is_object($event)) {
            EventName::register($event::class);
            $data = Hydrator::dehydrate($event);

            return new self(EventName::of($event), is_array($data) ? $data : []);
        }

        return new self(EventName::of($event), $payload);
    }

    public function isInternal(): bool
    {
        foreach (['done.', 'error.', 'unknown.', 'after.', 'item.', 'manual.'] as $prefix) {
            if (str_starts_with($this->name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
