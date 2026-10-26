<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Support\Duration;

/**
 * The periodic part of the runtime: fires due timers, recovers effects whose
 * worker died, rings work that lost its doorbell, purges expired messages.
 *
 * @internal
 */
final class Ticker
{
    public function __construct(
        private readonly Kernel $k,
        private readonly EffectExecutor $effects,
    ) {}

    /**
     * @return array{timers: int, recovered: int, due: int, rerung: int, purged: int}
     */
    public function tick(int $limit = 500): array
    {
        $now = $this->k->now();
        $report = ['timers' => 0, 'recovered' => 0, 'due' => 0, 'rerung' => 0, 'purged' => 0];

        foreach ($this->k->store->dueTimers($now, $limit) as $timer) {
            $fired = $this->k->tx(function () use ($timer): bool {
                if (! $this->k->store->fireTimer($timer->id)) {
                    return false;
                }
                $this->k->faults->hit('ticker.after_fire');
                $this->k->deliver($timer->instanceId, $timer->event, ['activation' => $timer->activation, 'firedAt' => $this->k->now()->format(DATE_RFC3339_EXTENDED)], 'timer:'.$timer->key, Actor::timer());
                $this->k->journal($timer->instanceId, 'timer.fired', ['key' => $timer->key, 'event' => $timer->event, 'due' => $timer->fireAt->format(DATE_RFC3339_EXTENDED)]);

                return true;
            });
            $report['timers'] += $fired ? 1 : 0;
        }

        foreach ($this->k->store->expiredEffects($now, $limit) as $effect) {
            $this->k->tx(fn () => $this->recover($effect));
            $report['recovered']++;
        }

        foreach ($this->k->store->dueEffects([EffectRecord::PENDING, EffectRecord::UNKNOWN], $now, $limit) as $effectId) {
            $this->k->ringEffect($effectId);
            $report['due']++;
        }

        foreach ($this->k->store->instancesWithPendingInbox($now->modify('-'.Duration::seconds($this->k->options->sweepAfter).' seconds'), $limit) as $instanceId) {
            $this->k->ringInstance($instanceId);
            $report['rerung']++;
        }

        $report['purged'] = $this->k->store->purgeMessages($now);

        return $report;
    }

    /**
     * An effect whose lease ran out: its worker died or hung mid-call.
     */
    private function recover(EffectRecord $effect): void
    {
        $fresh = $this->k->store->findEffect($effect->id);
        if ($fresh === null || ! in_array($fresh->status, [EffectRecord::RUNNING, EffectRecord::RECONCILING], true)) {
            return;
        }
        $this->k->journal($fresh->instanceId, 'effect.lease_expired', ['key' => $fresh->key, 'class' => $fresh->class, 'attempt' => $fresh->attempt]);

        if ($fresh->status === EffectRecord::RECONCILING) {
            $fresh->status = EffectRecord::UNKNOWN;
            $fresh->lockedBy = null;
            $fresh->leaseUntil = null;
            $fresh->nextAttemptAt = $this->k->now();
            $this->k->store->updateEffect($fresh);

            return;
        }
        if ($fresh->isAtMostOnce() && $fresh->kind !== EffectRecord::KIND_POLL) {
            // It may or may not have run: never retried automatically.
            $this->effects->markUnknown($fresh, 'the worker stopped during the call (lease expired)');

            return;
        }

        $retry = Retry::fromArray($fresh->retry);
        if ($fresh->kind !== EffectRecord::KIND_POLL && $retry->exhausted($fresh->attempt)) {
            $this->effects->fail($fresh, 'retries_exhausted', 'the worker stopped during the last attempt', [], exhausted: true);

            return;
        }
        $fresh->status = EffectRecord::PENDING;
        $fresh->lockedBy = null;
        $fresh->leaseUntil = null;
        $fresh->nextAttemptAt = $this->k->now();
        $this->k->store->updateEffect($fresh);
        $this->k->ringEffect($fresh->id);
    }
}
