<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Named points where tests make the runtime "die":
 *
 *     runner.before_commit           the step is computed, nothing is committed
 *     runner.after_commit            the step committed, doorbells not rung
 *     effect.after_claim             the effect is marked running, the handler not called
 *     effect.after_call              the handler returned, the result not recorded
 *     effect.after_record            the result recorded
 *     reconcile.after_call           a reconciliation returned, not recorded
 *     ticker.after_fire              a timer marked fired, its event not queued
 *
 * A crash rolls back the open transaction and leaves leases as they are, like
 * a killed worker would.
 */
final class FaultInjector
{
    /** @var array<string, int> point → hits to skip before crashing */
    private array $armed = [];

    /** @var array<string, int> */
    private array $hits = [];

    public function crashAt(string $point, int $skip = 0): void
    {
        $this->armed[$point] = $skip;
    }

    public function disarm(?string $point = null): void
    {
        if ($point === null) {
            $this->armed = [];
        } else {
            unset($this->armed[$point]);
        }
    }

    public function hit(string $point): void
    {
        $this->hits[$point] = ($this->hits[$point] ?? 0) + 1;
        if (! array_key_exists($point, $this->armed)) {
            return;
        }
        if ($this->armed[$point] > 0) {
            $this->armed[$point]--;

            return;
        }
        unset($this->armed[$point]);

        throw new SimulatedCrash("Simulated crash at {$point}");
    }

    public function hits(string $point): int
    {
        return $this->hits[$point] ?? 0;
    }
}
