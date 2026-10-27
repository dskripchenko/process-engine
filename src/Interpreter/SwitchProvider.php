<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Actor;

/**
 * Switches of switchable states (ADR-015): a stage of a pipeline that people
 * or the engine turn off. Unlike flags, a switch nobody touched is on.
 */
interface SwitchProvider
{
    public function isOn(string $switch): bool;

    public function set(string $switch, bool $on, Actor $by, ?string $reason = null): void;

    /**
     * @return array{on: bool, reason: ?string, by: ?array<string, mixed>, at: ?DateTimeImmutable}
     */
    public function state(string $switch): array;
}
