<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

/**
 * Who caused something: a user, an external party, the system, a timer or an
 * operator. The engine records it and does not interpret it.
 */
final class Actor
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $id = null,
        public readonly ?string $display = null,
    ) {}

    public static function user(string|int $id, ?string $display = null): self
    {
        return new self('user', (string) $id, $display);
    }

    public static function party(string $id, ?string $display = null): self
    {
        return new self('party', $id, $display);
    }

    public static function operator(string|int $id, ?string $display = null): self
    {
        return new self('operator', (string) $id, $display);
    }

    public static function system(?string $id = null): self
    {
        return new self('system', $id);
    }

    public static function timer(): self
    {
        return new self('timer');
    }

    /**
     * @return array{type: string, id: string|null, display: string|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'display' => $this->display];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! isset($data['type'])) {
            return null;
        }

        return new self((string) $data['type'], isset($data['id']) ? (string) $data['id'] : null, isset($data['display']) ? (string) $data['display'] : null);
    }
}
