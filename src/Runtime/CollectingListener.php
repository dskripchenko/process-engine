<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Keeps every signal: for tests and debugging.
 */
final class CollectingListener implements EventListener
{
    /** @var list<Signal> */
    public array $signals = [];

    public function handle(Signal $signal): void
    {
        $this->signals[] = $signal;
    }

    /**
     * @return list<Signal>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->signals, fn (Signal $s) => $s->type === $type));
    }
}
