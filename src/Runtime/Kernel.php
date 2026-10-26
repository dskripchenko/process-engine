<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Interpreter\Interpreter;
use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\JournalRecord;
use Dskripchenko\ProcessEngine\Store\MessageRecord;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Support\Duration;
use Dskripchenko\ProcessEngine\Support\Uuid;
use Throwable;

/**
 * The shared context of the runtime services: store, clock, interpreter and
 * the bookkeeping every write needs (journal, incidents, signals, doorbells
 * rung after commit).
 *
 * @internal
 */
final class Kernel
{
    /** @var list<array{0: string, 1: string}> */
    private array $rings = [];

    /** @var list<Signal> */
    private array $signals = [];

    private int $depth = 0;

    public readonly string $worker;

    public function __construct(
        public readonly Store $store,
        public readonly Clock $clock,
        public readonly Interpreter $interpreter,
        public readonly Definitions $definitions,
        public readonly Dispatcher $dispatcher,
        public readonly Container $container,
        public readonly FaultInjector $faults,
        public readonly EngineOptions $options,
        public readonly ?EventListener $listener = null,
        ?string $worker = null,
    ) {
        $this->worker = $worker ?? gethostname().':'.getmypid().':'.bin2hex(random_bytes(4));
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * A unit of work: commits through the store, then rings doorbells and
     * emits signals; on failure both are dropped.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  string|null  $faultAfterCommit  a fault point between commit and the doorbells
     * @return T
     */
    public function tx(callable $callback, ?string $faultAfterCommit = null): mixed
    {
        $outer = $this->depth === 0;
        $this->depth++;
        try {
            $result = $this->store->transaction($callback);
        } catch (Throwable $e) {
            $this->depth--;
            if ($outer) {
                $this->rings = [];
                $this->signals = [];
            }

            throw $e;
        }
        $this->depth--;
        if ($outer) {
            if ($faultAfterCommit !== null) {
                try {
                    $this->faults->hit($faultAfterCommit);
                } catch (Throwable $e) {
                    $this->rings = [];
                    $this->signals = [];

                    throw $e;
                }
            }
            $this->flush();
        }

        return $result;
    }

    public function ringInstance(string $id): void
    {
        $this->rings[] = ['instance', $id];
        if ($this->depth === 0) {
            $this->flush();
        }
    }

    public function ringEffect(string $id): void
    {
        $this->rings[] = ['effect', $id];
        if ($this->depth === 0) {
            $this->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function signal(string $type, string $instanceId, array $data = []): void
    {
        $this->signals[] = new Signal($type, $instanceId, $data);
        if ($this->depth === 0) {
            $this->flush();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function journal(string $instanceId, string $type, array $data = [], ?Actor $actor = null, ?string $reason = null): void
    {
        $this->store->appendJournal(new JournalRecord($instanceId, $type, $data, $this->now(), $actor?->toArray(), $reason));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function incident(string $instanceId, string $type, string $message, array $details = [], ?string $effectId = null): IncidentRecord
    {
        $record = new IncidentRecord(Uuid::v7($this->now()), $instanceId, $type, $message, $details, $effectId, IncidentRecord::OPEN, $this->now());
        $this->store->insertIncident($record);
        $this->store->setHasIncidents($instanceId, true);
        $this->journal($instanceId, 'incident.opened', ['id' => $record->id, 'type' => $type, 'message' => $message, 'effect' => $effectId]);
        $this->signal('incident.opened', $instanceId, ['id' => $record->id, 'type' => $type, 'message' => $message]);

        return $record;
    }

    /**
     * Queue an event for an instance. False when the key was already queued.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(string $instanceId, string $name, array $payload, string $key, ?Actor $actor = null): bool
    {
        $inserted = $this->store->insertInbox(new InboxRecord(
            id: null,
            instanceId: $instanceId,
            name: $name,
            payload: $payload,
            key: $key,
            actor: $actor?->toArray(),
            status: InboxRecord::PENDING,
            receivedAt: $this->now(),
        ));
        if ($inserted) {
            $this->ringInstance($instanceId);
        }

        return $inserted;
    }

    /**
     * Deliver an event to the instances subscribed under the correlation key,
     * or buffer it until one subscribes. Returns the instances it reached.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>|null null when buffered, [] when it was a duplicate
     */
    public function publish(string $event, array $payload, string $correlationKey, string $key, ?Actor $actor = null): ?array
    {
        $subscribers = $this->store->subscribers($event, $correlationKey);
        if ($subscribers === []) {
            $inserted = $this->store->insertMessage(new MessageRecord(
                id: Uuid::v7($this->now()),
                event: $event,
                correlationKey: $correlationKey,
                payload: $payload,
                key: $key,
                actor: $actor?->toArray(),
                expiresAt: Duration::add($this->now(), $this->options->messageTtl),
                createdAt: $this->now(),
            ));

            return $inserted ? null : [];
        }
        $reached = [];
        foreach ($subscribers as $subscription) {
            if ($this->deliver($subscription->instanceId, $event, $payload, 'publish:'.$key, $actor)) {
                $reached[] = $subscription->instanceId;
            }
        }

        return array_values(array_unique($reached));
    }

    private function flush(): void
    {
        $rings = $this->rings;
        $signals = $this->signals;
        $this->rings = [];
        $this->signals = [];
        foreach ($rings as [$kind, $id]) {
            if ($kind === 'instance') {
                $this->dispatcher->instanceReady($id);
            } else {
                $this->dispatcher->effectReady($id);
            }
        }
        if ($this->listener !== null) {
            foreach ($signals as $signal) {
                $this->listener->handle($signal);
            }
        }
    }
}
