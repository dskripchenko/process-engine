<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Batch\BatchResult;
use Dskripchenko\ProcessEngine\Batch\ItemOutcome;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Store\BatchItemRecord;
use Dskripchenko\ProcessEngine\Store\BatchRecord;
use Dskripchenko\ProcessEngine\Store\BatchStore;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Dskripchenko\ProcessEngine\Support\Arguments;
use Dskripchenko\ProcessEngine\Support\Hydrator;
use Dskripchenko\ProcessEngine\Support\Uuid;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * Batch invocations (ADR-014): a state works through many elements taken from
 * a source page by page, a chunk per effect, a few chunks at a time.
 *
 * The batch is a row versioned by `seq`; its elements are rows of their own.
 * Every change — a chunk recorded, the next chunks scheduled (refill), the
 * batch finished — happens in one transaction under the batch's version, so
 * concurrent chunk completions serialize on it.
 *
 * @internal
 */
final class Batches
{
    /** Attempts of a transaction that lost the race for the batch's version. */
    private const CONFLICT_RETRIES = 20;

    public function __construct(private readonly Kernel $k) {}

    public static function supported(object $store): bool
    {
        return $store instanceof BatchStore;
    }

    // Start and stop ----------------------------------------------------------

    /**
     * The batch of a state just entered: its row and a first chunk without
     * keys that only refills (starts the real chunks outside the step).
     *
     * @param  array<string, mixed>  $d  the START_BATCH command
     */
    public function start(InstanceRecord $instance, array $d): void
    {
        $store = $this->store();
        if ($store === null) {
            $this->k->incident($instance->id, IncidentRecord::HANDLER_MISSING, 'The store keeps no batches: '.$this->k->store::class.' does not implement BatchStore', ['key' => $d['key']]);

            return;
        }
        $handler = (string) $d['handler'];
        $semantics = Steps::semantics($handler);
        if ($semantics === null || $semantics->name() !== 'idempotent') {
            $this->k->incident($instance->id, IncidentRecord::HANDLER_MISSING, "Batch handler {$handler} is missing or not #[Idempotent]", ['key' => $d['key']]);

            return;
        }
        $retry = isset($d['retry']) && is_array($d['retry']) ? Retry::fromArray($d['retry']) : $semantics->retry();

        $batch = new BatchRecord(
            id: Uuid::v7($this->k->now()),
            instanceId: $instance->id,
            key: (string) $d['key'],
            invoke: (string) $d['invoke'],
            state: (string) $d['state'],
            activation: (string) $d['activation'],
            handler: $handler,
            source: (string) $d['source'],
            chunk: max(1, (int) ($d['chunk'] ?? 100)),
            concurrency: max(1, (int) ($d['concurrency'] ?? 1)),
            itemAttempts: max(1, (int) ($d['itemAttempts'] ?? 3)),
            retry: $retry->toArray() + ['nonRetryable' => $semantics->nonRetryable()],
            context: (array) ($d['context'] ?? []),
            contextClass: isset($d['contextClass']) ? (string) $d['contextClass'] : null,
            createdAt: $this->k->now(),
        );
        $store->insertBatch($batch);
        $this->k->journal($instance->id, 'batch.started', ['batch' => $batch->id, 'key' => $batch->key, 'handler' => $handler, 'source' => $batch->source]);
        $this->k->signal('batch.started', $instance->id, ['batch' => $batch->id, 'invoke' => $batch->invoke]);

        $feeder = $this->effect($batch, $batch->key.'/feed', null, array_values(array_map('strval', (array) ($d['scopes'] ?? []))));
        if ($this->k->store->insertEffect($feeder)) {
            $this->k->ringEffect($feeder->id);
        }
    }

    /**
     * The state of the batches under the prefix was left: they stop, their
     * waiting elements are deferred. Their pending chunks are cancelled by
     * the activation's cancellation; running ones record outcomes but start
     * nothing more.
     */
    public function cancel(string $instanceId, string $prefix, string $reason): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }
        foreach ($store->batches($instanceId, BatchRecord::RUNNING) as $batch) {
            if (! str_starts_with($batch->key, $prefix)) {
                continue;
            }
            $this->withBatch($batch->id, function (BatchRecord $b) use ($store, $reason): void {
                if (! $b->isRunning()) {
                    return;
                }
                $this->settleWaiting($store, $b, ItemOutcome::DEFERRED, $reason);
                $b->status = BatchRecord::CANCELLED;
                $b->running = 0;
                $b->finishedAt = $this->k->now();
                $this->k->journal($b->instanceId, 'batch.cancelled', ['batch' => $b->id, 'reason' => $reason, 'counters' => $b->summary()]);
                $this->k->signal('batch.cancelled', $b->instanceId, ['batch' => $b->id, 'invoke' => $b->invoke]);
            });
        }
    }

    /**
     * A resumed instance: its batches start chunks again.
     */
    public function resume(string $instanceId): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }
        foreach ($store->batches($instanceId, BatchRecord::RUNNING) as $batch) {
            $this->withBatch($batch->id, fn (BatchRecord $b) => $this->refill($b));
        }
    }

    // Chunks ------------------------------------------------------------------

    /**
     * Run a claimed chunk: the feeder only refills; a chunk with keys calls
     * the handler outside any transaction, then records the outcomes.
     *
     * @return BatchResult|null null for the feeder (nothing to record)
     */
    public function call(EffectRecord $effect): ?BatchResult
    {
        $keys = $effect->input['keys'] ?? null;
        $batch = $this->store()?->findBatch((string) ($effect->input['batch'] ?? ''));
        if ($batch === null) {
            return BatchResult::of([]);
        }
        if ($keys === null) {
            return null;
        }
        $result = $this->invoke($batch, $effect->class, 'handle', ['keys' => array_values(array_map('strval', (array) $keys))], $effect);

        return $result instanceof BatchResult ? $result : BatchResult::of([]);
    }

    /**
     * The feeder ran: mark it and refill.
     */
    public function fed(EffectRecord $effect): void
    {
        $this->finishEffect($effect, EffectRecord::SUCCEEDED, null, null);
        $this->withBatch((string) $effect->input['batch'], fn (BatchRecord $b) => $this->refill($b));
    }

    /**
     * A chunk returned: its elements get their outcomes, the next chunks start.
     */
    public function complete(EffectRecord $effect, BatchResult $result): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }
        $this->withBatch((string) $effect->input['batch'], function (BatchRecord $b) use ($store, $effect, $result): void {
            $keys = array_values(array_map('strval', (array) ($effect->input['keys'] ?? [])));
            $outcomes = [];
            foreach ($keys as $key) {
                $outcomes[$key] = $result->outcomes[$key] ?? ItemOutcome::failed('no outcome');
            }
            $summary = $this->record($store, $b, $effect->id, $outcomes);
            $chunkRunning = $this->finishEffect($effect, EffectRecord::SUCCEEDED, $summary, null);
            if ($chunkRunning && $b->isRunning()) {
                $b->running = max(0, $b->running - 1);
                $this->refill($b);
            }
        });
    }

    /**
     * A chunk ran out of attempts: each of its elements loses one attempt,
     * the batch goes on.
     */
    public function exhausted(EffectRecord $effect, string $message): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }
        $this->withBatch((string) $effect->input['batch'], function (BatchRecord $b) use ($store, $effect, $message): void {
            $outcomes = [];
            foreach ((array) ($effect->input['keys'] ?? []) as $key) {
                $outcomes[(string) $key] = ItemOutcome::failed('retries exhausted: '.$message);
            }
            $this->record($store, $b, $effect->id, $outcomes);
            $chunkRunning = $this->finishEffect($effect, EffectRecord::FAILED, null, ['code' => 'retries_exhausted', 'message' => $message]);
            if ($chunkRunning && $b->isRunning()) {
                $b->running = max(0, $b->running - 1);
                $this->refill($b);
            }
        });
    }

    /**
     * A chunk failed for certain (EffectFailed): the batch stops, what has not
     * run is deferred, the process gets `error.invoke` with the code.
     */
    public function stop(EffectRecord $effect, string $code, string $message): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }
        $this->withBatch((string) $effect->input['batch'], function (BatchRecord $b) use ($store, $effect, $code, $message): void {
            $this->finishEffect($effect, EffectRecord::FAILED, null, ['code' => $code, 'message' => $message]);
            if (! $b->isRunning()) {
                return;
            }
            $this->settleWaiting($store, $b, ItemOutcome::DEFERRED, $message);
            $this->cancelChunks($b);
            $b->status = BatchRecord::FAILED;
            $b->error = ['code' => $code, 'message' => $message];
            $b->running = 0;
            $b->finishedAt = $this->k->now();
            $this->k->journal($b->instanceId, 'batch.failed', ['batch' => $b->id, 'code' => $code, 'message' => $message, 'counters' => $b->summary()]);
            $this->k->signal('batch.failed', $b->instanceId, ['batch' => $b->id, 'invoke' => $b->invoke, 'code' => $code, 'message' => $message]);
            $this->k->deliver($b->instanceId, 'error.invoke.'.$b->invoke, [
                'activation' => $b->activation, 'code' => $code, 'message' => $message, 'output' => $b->summary(), 'batch' => $b->id,
            ], 'batch:'.$b->id.':error');
        });
    }

    // Operations ----------------------------------------------------------------

    /**
     * Elements of a running batch back into the queue, attempts from zero.
     *
     * @param  list<string>  $statuses
     */
    public function retryItems(string $instanceId, string $batchId, array $statuses): string
    {
        $store = $this->store() ?? throw new InvalidArgumentException('The store keeps no batches');
        $statuses = array_values(array_intersect($statuses, [ItemOutcome::FAILED, ItemOutcome::DEFERRED, ItemOutcome::NOT_FOUND, ItemOutcome::SKIPPED]));
        if ($statuses === []) {
            throw new InvalidArgumentException('Name the statuses to retry: failed, deferred, not_found or skipped');
        }
        $moved = 0;
        $this->withBatch($batchId, function (BatchRecord $b) use ($store, $instanceId, $statuses, &$moved): void {
            if ($b->instanceId !== $instanceId) {
                throw new InvalidArgumentException("Batch {$b->id} is not of instance {$instanceId}");
            }
            if (! $b->isRunning()) {
                throw new InvalidArgumentException("Batch {$b->id} is {$b->status}: only a running batch takes elements back");
            }
            foreach ($statuses as $status) {
                $count = $store->moveBatchItems($b->id, [$status], BatchItemRecord::PENDING, null, $this->k->now(), resetAttempts: true);
                $b->counters[$status] = max(0, ($b->counters[$status] ?? 0) - $count);
                $moved += $count;
            }
            $this->refill($b);
        });

        return "{$moved} elements queued again";
    }

    /**
     * The rest of a running batch is skipped; the state goes on as if it finished.
     */
    public function skip(string $instanceId, string $batchId, ?string $reason): string
    {
        $store = $this->store() ?? throw new InvalidArgumentException('The store keeps no batches');
        $skipped = 0;
        $this->withBatch($batchId, function (BatchRecord $b) use ($store, $instanceId, $reason, &$skipped): void {
            if ($b->instanceId !== $instanceId) {
                throw new InvalidArgumentException("Batch {$b->id} is not of instance {$instanceId}");
            }
            if (! $b->isRunning()) {
                throw new InvalidArgumentException("Batch {$b->id} is {$b->status}");
            }
            $skipped = $this->settleWaiting($store, $b, ItemOutcome::SKIPPED, $reason ?? 'skipped by an operator');
            $this->cancelChunks($b);
            $b->exhausted = true;
            $b->running = 0;
            $this->finish($b, ['skipped' => true]);
        });

        return "{$skipped} elements skipped";
    }

    // Inside ------------------------------------------------------------------

    /**
     * Schedule chunks while there is room, reading the source when the queue
     * of elements runs short; finish when nothing is left.
     */
    private function refill(BatchRecord $b): void
    {
        $store = $this->store();
        if ($store === null || ! $b->isRunning()) {
            return;
        }
        $instance = $this->k->store->findInstance($b->instanceId);
        if ($instance === null || $instance->isFinished()) {
            return;
        }
        if ($b->chunks === 0 && $b->seen === 0 && $b->total === null && method_exists($b->source, 'count')) {
            $count = $this->invoke($b, $b->source, 'count', []);
            $b->total = is_int($count) ? $count : null;
        }
        $suspended = $instance->status === Snapshot::SUSPENDED;

        while (! $suspended && $b->running < $b->concurrency) {
            $items = $store->batchItems($b->id, BatchItemRecord::PENDING, $b->chunk);
            if (count($items) < $b->chunk && ! $b->exhausted) {
                $this->read($store, $b);

                continue;
            }
            if ($items === []) {
                break;
            }
            $this->schedule($store, $b, $items);
        }

        if ($b->running === 0 && $b->exhausted && $store->batchItems($b->id, BatchItemRecord::PENDING, 1) === []) {
            $this->finish($b);
        }
    }

    /**
     * The next page of the source into the queue of elements.
     */
    private function read(BatchStore $store, BatchRecord $b): void
    {
        $page = $this->invoke($b, $b->source, 'keys', ['after' => $b->cursor, 'limit' => $b->chunk * $b->concurrency]);
        $keys = array_values(array_map('strval', is_array($page) ? $page : []));
        if ($keys === []) {
            $b->exhausted = true;

            return;
        }
        $last = $keys[count($keys) - 1];
        $added = $store->insertBatchItems($b->id, $keys, $b->seen, $this->k->now());
        if ($added === 0 && $last === $b->cursor) {
            // The source repeats itself: treat it as read to the end.
            $b->exhausted = true;
            $this->k->journal($b->instanceId, 'batch.source_stuck', ['batch' => $b->id, 'cursor' => $b->cursor]);

            return;
        }
        $b->seen += $added;
        $b->cursor = $last;
    }

    /**
     * @param  list<BatchItemRecord>  $items
     */
    private function schedule(BatchStore $store, BatchRecord $b, array $items): void
    {
        $effect = $this->effect($b, $b->key.'/chunk:'.$b->chunks, array_map(fn (BatchItemRecord $i) => $i->key, $items), []);
        $b->chunks++;
        $b->running++;
        foreach ($items as $item) {
            $item->status = BatchItemRecord::QUEUED;
            $item->effectId = $effect->id;
            $item->updatedAt = $this->k->now();
        }
        $store->updateBatchItems($items);
        if ($this->k->store->insertEffect($effect)) {
            $this->k->ringEffect($effect->id);
        }
    }

    /**
     * Record outcomes of a chunk's elements; elements no longer held by this
     * chunk (cancelled, stopped, skipped) are left alone.
     *
     * @param  array<string, ItemOutcome>  $outcomes
     * @return array<string, int> outcome => number, of this chunk
     */
    private function record(BatchStore $store, BatchRecord $b, string $effectId, array $outcomes): array
    {
        $items = $store->findBatchItems($b->id, array_keys($outcomes));
        $changed = [];
        $summary = [];
        foreach ($outcomes as $key => $outcome) {
            $item = $items[$key] ?? null;
            if ($item === null || $item->status !== BatchItemRecord::QUEUED || $item->effectId !== $effectId) {
                continue;
            }
            $item->reason = $outcome->reason;
            $item->updatedAt = $this->k->now();
            $item->effectId = null;
            $status = $outcome->status;
            if ($status === ItemOutcome::FAILED) {
                $item->attempts++;
                if ($item->attempts < $b->itemAttempts && $b->isRunning()) {
                    $status = BatchItemRecord::PENDING;
                }
            } else {
                $item->attempts++;
            }
            $item->status = $status;
            if (in_array($status, BatchRecord::COUNTED, true)) {
                $b->counters[$status] = ($b->counters[$status] ?? 0) + 1;
            }
            $summary[$status] = ($summary[$status] ?? 0) + 1;
            $changed[] = $item;
        }
        $store->updateBatchItems($changed);

        return $summary;
    }

    /**
     * Every waiting element (pending or queued) gets a final status.
     */
    private function settleWaiting(BatchStore $store, BatchRecord $b, string $status, ?string $reason): int
    {
        $moved = $store->moveBatchItems($b->id, [BatchItemRecord::PENDING, BatchItemRecord::QUEUED], $status, $reason, $this->k->now());
        $b->counters[$status] = ($b->counters[$status] ?? 0) + $moved;

        return $moved;
    }

    private function cancelChunks(BatchRecord $b): void
    {
        foreach ($this->k->store->effects($b->instanceId, [EffectRecord::PENDING]) as $effect) {
            if ($effect->kind === EffectRecord::KIND_BATCH && ($effect->input['batch'] ?? null) === $b->id) {
                $effect->status = EffectRecord::CANCELLED;
                $this->k->store->updateEffect($effect);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finish(BatchRecord $b, array $extra = []): void
    {
        $b->status = BatchRecord::DONE;
        $b->finishedAt = $this->k->now();
        $output = $b->summary() + $extra;
        $this->k->journal($b->instanceId, 'batch.done', ['batch' => $b->id, 'counters' => $output]);
        $this->k->signal('batch.done', $b->instanceId, ['batch' => $b->id, 'invoke' => $b->invoke, 'counters' => $output]);
        $this->k->deliver($b->instanceId, 'done.invoke.'.$b->invoke, [
            'activation' => $b->activation, 'output' => $output, 'batch' => $b->id,
        ], 'batch:'.$b->id.':done');
    }

    /**
     * Close a chunk's effect row. False when it was no longer running (a
     * stale completion after recovery or cancellation): then it stays as it is.
     *
     * @param  array<string, mixed>|null  $result
     * @param  array<string, mixed>|null  $error
     */
    private function finishEffect(EffectRecord $effect, string $status, ?array $result, ?array $error): bool
    {
        $fresh = $this->k->store->findEffect($effect->id) ?? $effect;
        if ($fresh->status !== EffectRecord::RUNNING) {
            // Cancelled meanwhile, or recovered after its lease ran out and
            // queued again: the queued run closes it.
            return false;
        }
        $fresh->status = $status;
        $fresh->result = $result;
        $fresh->error = $error;
        $fresh->finishedAt = $this->k->now();
        $fresh->lockedBy = null;
        $fresh->leaseUntil = null;
        $fresh->nextAttemptAt = null;
        $this->k->store->updateEffect($fresh);

        return true;
    }

    /**
     * @param  list<string>|null  $keys  null for the feeder
     * @param  list<string>  $scopes
     */
    private function effect(BatchRecord $b, string $key, ?array $keys, array $scopes): EffectRecord
    {
        return new EffectRecord(
            id: Uuid::v7($this->k->now()),
            instanceId: $b->instanceId,
            key: $key,
            kind: EffectRecord::KIND_BATCH,
            class: $b->handler,
            semantics: 'idempotent',
            retry: $b->retry,
            status: EffectRecord::PENDING,
            input: [
                'batch' => $b->id,
                'keys' => $keys,
                'invoke' => $b->invoke,
                'activation' => $b->activation,
                'state' => $b->state,
                'index' => null,
                'item' => null,
                'scopes' => $scopes,
            ],
            nextAttemptAt: $this->k->now(),
            createdAt: $this->k->now(),
        );
    }

    /**
     * Call a method of the handler or the source with arguments resolved by
     * type and name: the batch's context, `Effect` (for chunks), and the
     * named extras.
     *
     * @param  array<string, mixed>  $named
     */
    private function invoke(BatchRecord $b, string $class, string $method, array $named, ?EffectRecord $effect = null): mixed
    {
        $target = $this->k->container->get($class);
        $reflection = new ReflectionMethod($target, $method);
        $objects = [];
        if ($b->contextClass !== null && class_exists($b->contextClass)) {
            $objects[$b->contextClass] = Hydrator::hydrate($b->contextClass, $b->context);
        }
        if ($effect !== null) {
            $objects[Effect::class] = new Effect(
                key: $effect->key,
                instanceId: $effect->instanceId,
                attempt: $effect->attempt,
                now: $this->k->now(),
                input: ['batch' => $b->id],
            );
        }
        $named = ['context' => $b->context] + $named;

        return $reflection->invokeArgs($target, Arguments::resolve($reflection, $objects, $named));
    }

    /**
     * Change a batch under its version, in a transaction; a lost race reloads
     * it and tries again. Inside an outer transaction it tries once and lets
     * the conflict roll the outer one back.
     *
     * @param  callable(BatchRecord): void  $change
     */
    private function withBatch(string $batchId, callable $change): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }
        $attempts = $this->k->inTransaction() ? 1 : self::CONFLICT_RETRIES;
        for ($i = 1; ; $i++) {
            try {
                $this->k->tx(function () use ($store, $batchId, $change): void {
                    $batch = $store->findBatch($batchId);
                    if ($batch === null) {
                        return;
                    }
                    $seq = $batch->seq;
                    $change($batch);
                    if (! $store->updateBatch($batch, $seq)) {
                        throw new ConcurrencyConflict("Batch {$batchId} changed meanwhile");
                    }
                });

                return;
            } catch (ConcurrencyConflict $e) {
                if ($i >= $attempts) {
                    throw $e;
                }
            }
        }
    }

    private function store(): ?BatchStore
    {
        return $this->k->store instanceof BatchStore ? $this->k->store : null;
    }
}
