<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

/**
 * An event the current configuration accepts: what a client can do now.
 * `conditional` means the guard depends on the event's data, so the outcome
 * is known only when it is sent.
 */
final class ExpectedEvent
{
    public function __construct(
        public readonly string $event,
        public readonly ?string $label,
        public readonly ?string $target,
        public readonly string $state,
        public readonly bool $conditional = false,
        public readonly string $kind = 'event',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'label' => $this->label,
            'target' => $this->target,
            'state' => $this->state,
            'conditional' => $this->conditional,
            'kind' => $this->kind,
        ];
    }
}
