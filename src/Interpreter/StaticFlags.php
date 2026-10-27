<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Actor;

/**
 * Flags from a fixed map; unknown flags are off. Also the switches of
 * switchable states, kept in memory; unknown switches are on.
 */
final class StaticFlags implements FlagProvider, SwitchProvider
{
    /** @var array<string, array{on: bool, reason: ?string, by: ?array<string, mixed>, at: ?DateTimeImmutable}> */
    private array $switches = [];

    /**
     * @param  array<string, bool>  $flags
     */
    public function __construct(private array $flags = []) {}

    public function enabled(string $flag): bool
    {
        return $this->flags[$flag] ?? false;
    }

    /**
     * A flag; with an actor — a switch (SwitchProvider).
     */
    public function set(string $flag, bool $on, ?Actor $by = null, ?string $reason = null): void
    {
        if ($by === null) {
            $this->flags[$flag] = $on;

            return;
        }
        $this->switches[$flag] = ['on' => $on, 'reason' => $reason, 'by' => $by->toArray(), 'at' => new DateTimeImmutable];
    }

    public function isOn(string $switch): bool
    {
        return $this->switches[$switch]['on'] ?? true;
    }

    public function state(string $switch): array
    {
        return $this->switches[$switch] ?? ['on' => true, 'reason' => null, 'by' => null, 'at' => null];
    }
}
