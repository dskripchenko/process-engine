<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Collects rings in memory; Engine::drain() works them off in the same process.
 */
final class SyncDispatcher implements Dispatcher
{
    /** @var array<string, true> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $effects = [];

    public function instanceReady(string $instanceId): void
    {
        $this->instances[$instanceId] = true;
    }

    public function effectReady(string $effectId): void
    {
        $this->effects[$effectId] = true;
    }

    public function nextInstance(): ?string
    {
        $id = array_key_first($this->instances);
        if ($id !== null) {
            unset($this->instances[$id]);
        }

        return $id;
    }

    public function nextEffect(): ?string
    {
        $id = array_key_first($this->effects);
        if ($id !== null) {
            unset($this->effects[$id]);
        }

        return $id;
    }

    public function isEmpty(): bool
    {
        return $this->instances === [] && $this->effects === [];
    }

    public function clear(): void
    {
        $this->instances = [];
        $this->effects = [];
    }
}
