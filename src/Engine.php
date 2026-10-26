<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

use DateTimeInterface;
use Dskripchenko\ProcessEngine\Interpreter\BusinessCalendar;
use Dskripchenko\ProcessEngine\Interpreter\FlagProvider;
use Dskripchenko\ProcessEngine\Interpreter\Interpreter;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Interpreter\WeekdayCalendar;
use Dskripchenko\ProcessEngine\Query\InstanceView;
use Dskripchenko\ProcessEngine\Runtime\Clock;
use Dskripchenko\ProcessEngine\Runtime\Container;
use Dskripchenko\ProcessEngine\Runtime\Definitions;
use Dskripchenko\ProcessEngine\Runtime\Dispatcher;
use Dskripchenko\ProcessEngine\Runtime\EffectExecutor;
use Dskripchenko\ProcessEngine\Runtime\EngineOptions;
use Dskripchenko\ProcessEngine\Runtime\EventListener;
use Dskripchenko\ProcessEngine\Runtime\FaultInjector;
use Dskripchenko\ProcessEngine\Runtime\InstanceNotFound;
use Dskripchenko\ProcessEngine\Runtime\InstanceRunner;
use Dskripchenko\ProcessEngine\Runtime\Kernel;
use Dskripchenko\ProcessEngine\Runtime\ManualOperations;
use Dskripchenko\ProcessEngine\Runtime\Migrator;
use Dskripchenko\ProcessEngine\Runtime\Receipt;
use Dskripchenko\ProcessEngine\Runtime\SimpleContainer;
use Dskripchenko\ProcessEngine\Runtime\Steps;
use Dskripchenko\ProcessEngine\Runtime\SyncDispatcher;
use Dskripchenko\ProcessEngine\Runtime\SystemClock;
use Dskripchenko\ProcessEngine\Runtime\Ticker;
use Dskripchenko\ProcessEngine\Store\DefinitionRecord;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InMemoryStore;
use Dskripchenko\ProcessEngine\Store\InstanceQuery;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Store\TimerRecord;
use Dskripchenko\ProcessEngine\Support\Duration;
use Dskripchenko\ProcessEngine\Support\Hydrator;
use Dskripchenko\ProcessEngine\Support\Uuid;
use InvalidArgumentException;

/**
 * The entry point: start processes, deliver events, look at instances,
 * operate on them, and drive the runtime (workers call runInstance(),
 * executeEffect() and tick(); the synchronous mode calls drain()).
 */
final class Engine
{
    public readonly Kernel $kernel;

    private readonly Steps $steps;

    private readonly InstanceRunner $runner;

    private readonly EffectExecutor $executor;

    private readonly Ticker $ticker;

    private readonly ManualOperations $manual;

    public function __construct(
        Store $store,
        ?Container $container = null,
        ?Dispatcher $dispatcher = null,
        ?Clock $clock = null,
        EngineOptions $options = new EngineOptions,
        ?EventListener $listener = null,
        ?FaultInjector $faults = null,
        ?FlagProvider $flags = null,
        BusinessCalendar $calendar = new WeekdayCalendar,
        bool $allowRedefineUnused = false,
        ?string $worker = null,
    ) {
        $clock ??= new SystemClock;
        $this->kernel = new Kernel(
            store: $store,
            clock: $clock,
            interpreter: new Interpreter($flags, $calendar),
            definitions: new Definitions($store, $clock, $allowRedefineUnused),
            dispatcher: $dispatcher ?? new SyncDispatcher,
            container: $container ?? new SimpleContainer,
            faults: $faults ?? new FaultInjector,
            options: $options,
            listener: $listener,
            worker: $worker,
        );
        $this->steps = new Steps($this->kernel);
        $this->executor = new EffectExecutor($this->kernel);
        $this->manual = new ManualOperations($this->kernel, $this->steps, $this->executor);
        $this->runner = new InstanceRunner($this->kernel, $this->steps, $this->manual);
        $this->ticker = new Ticker($this->kernel, $this->executor);
    }

    /**
     * An engine on an in-memory store with a synchronous dispatcher.
     */
    public static function inMemory(?Clock $clock = null, ?Container $container = null, EngineOptions $options = new EngineOptions, ?FaultInjector $faults = null, ?EventListener $listener = null): self
    {
        return new self(new InMemoryStore, $container, new SyncDispatcher, $clock, $options, $listener, $faults);
    }

    public function setMigrator(Migrator $migrator): void
    {
        $this->manual->setMigrator($migrator);
    }

    // Starting ----------------------------------------------------------------

    /**
     * Start an instance of a process class (its current version) or of a
     * stored process name. With `key`, starting is idempotent: the same key
     * returns the instance already started with it.
     *
     * @param  class-string<Process>|string  $process
     * @param  object|array<string, mixed>  $context
     */
    public function start(
        string $process,
        object|array $context = [],
        ?string $key = null,
        ?Actor $by = null,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        ?int $version = null,
    ): InstanceView {
        $record = $this->definitionRecord($process, $version);
        if ($key !== null) {
            $existing = $this->kernel->store->findInstanceByKey($record->name, $key);
            if ($existing !== null) {
                return $this->view($existing);
            }
        }
        [$data, $class] = self::contextData($context);

        $instance = $this->kernel->tx(fn () => $this->steps->create(
            record: $record,
            context: $data,
            contextClass: $class,
            businessKey: $key,
            subjectType: $subjectType,
            subjectId: $subjectId !== null ? (string) $subjectId : null,
            actor: $by,
        ));

        return $this->get($instance->id);
    }

    /**
     * Bring a process that ran outside the engine in, straight into the given
     * states: entry actions do not run, timers, polls, subscriptions and
     * invocations of those states start.
     *
     * @param  class-string<Process>|string  $process
     * @param  list<string|\BackedEnum>  $in
     * @param  object|array<string, mixed>  $context
     */
    public function adopt(
        string $process,
        array $in,
        object|array $context = [],
        ?string $key = null,
        ?Actor $by = null,
        ?string $reason = null,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        ?int $version = null,
    ): InstanceView {
        $record = $this->definitionRecord($process, $version);
        if ($key !== null && ($existing = $this->kernel->store->findInstanceByKey($record->name, $key)) !== null) {
            return $this->view($existing);
        }
        $definition = $this->kernel->definitions->load($record->id);
        $states = array_map(fn ($s) => $s instanceof \BackedEnum ? (string) $s->value : $s, $in);
        foreach ($states as $state) {
            if (! $definition->has($state)) {
                throw new InvalidArgumentException("Process {$record->name} has no state `{$state}`");
            }
        }
        [$data, $class] = self::contextData($context);
        $instance = $this->kernel->tx(fn () => $this->steps->create(
            record: $record,
            context: $data,
            contextClass: $class,
            businessKey: $key,
            subjectType: $subjectType,
            subjectId: $subjectId !== null ? (string) $subjectId : null,
            actor: $by ?? Actor::system(),
            adoptStates: array_values($states),
            reason: $reason,
        ));

        return $this->get($instance->id);
    }

    // Events ------------------------------------------------------------------

    /**
     * Queue an event for an instance. The key makes delivery idempotent:
     * a repeat with the same key is reported as a duplicate and ignored.
     *
     * @param  array<string, mixed>  $payload  for a named event
     */
    public function send(string $instanceId, object|string $event, array $payload = [], ?string $key = null, ?Actor $by = null): Receipt
    {
        $e = Event::from($event, $payload);
        if (str_starts_with($e->name, 'manual.')) {
            throw new InvalidArgumentException('Operator actions go through the engine methods, not send()');
        }
        $this->require($instanceId);
        $key ??= 'auto:'.Uuid::v7($this->kernel->now());
        $inserted = $this->kernel->tx(fn () => $this->kernel->deliver($instanceId, $e->name, $e->payload, $key, $by));

        return new Receipt($inserted ? Receipt::ACCEPTED : Receipt::DUPLICATE, [$instanceId]);
    }

    /**
     * Send only if the current configuration accepts the event now (an
     * optimistic check for interfaces); otherwise rejected with what is expected.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendStrict(string $instanceId, object|string $event, array $payload = [], ?string $key = null, ?Actor $by = null): Receipt
    {
        $e = Event::from($event, $payload);
        $view = $this->get($instanceId);
        $expected = array_values(array_unique(array_map(fn ($x) => $x->event, array_filter($view->expects(), fn ($x) => $x->kind === 'event'))));
        if (! in_array($e->name, $expected, true)) {
            return new Receipt(Receipt::REJECTED, [$instanceId], $expected);
        }

        return $this->send($instanceId, $e, [], $key, $by);
    }

    /**
     * Deliver an event to the instances waiting for it under a correlation
     * key; buffered for a while when none waits yet.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(object|string $event, string $correlate, array $payload = [], ?string $key = null, ?Actor $by = null): Receipt
    {
        $e = Event::from($event, $payload);
        $key ??= 'auto:'.Uuid::v7($this->kernel->now());
        $reached = $this->kernel->tx(fn () => $this->kernel->publish($e->name, $e->payload, $correlate, $key, $by));

        return match (true) {
            $reached === null => new Receipt(Receipt::BUFFERED),
            $reached === [] => new Receipt(Receipt::DUPLICATE),
            default => new Receipt(Receipt::ACCEPTED, $reached),
        };
    }

    /**
     * A signed token for a callback addressed to the current activation of a
     * state (hand it to the other side in a callback URL).
     */
    public function taskToken(string $instanceId, string $state): string
    {
        $view = $this->get($instanceId);
        if (! in_array($state, $view->active(), true)) {
            throw new InvalidArgumentException("State `{$state}` is not active");
        }
        $body = rtrim(strtr(base64_encode((string) json_encode(['i' => $instanceId, 'a' => $view->snapshot()->activation($state)])), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, $this->kernel->options->secret);
    }

    /**
     * Deliver an event through a task token; rejected when the token is forged
     * or its activation is over.
     *
     * @param  array<string, mixed>  $payload
     */
    public function complete(string $token, object|string $event, array $payload = [], ?string $key = null, ?Actor $by = null): Receipt
    {
        [$body, $mac] = array_pad(explode('.', $token, 2), 2, '');
        if (! hash_equals(hash_hmac('sha256', $body, $this->kernel->options->secret), $mac)) {
            throw new InvalidArgumentException('Invalid task token');
        }
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        $instanceId = (string) ($data['i'] ?? '');
        $activation = (string) ($data['a'] ?? '');
        $state = substr($activation, 0, (int) strrpos($activation, '@'));
        $view = $this->find($instanceId);
        if ($view === null || $view->isFinished() || $view->snapshot()->activation($state) !== $activation || ! in_array($state, $view->active(), true)) {
            return new Receipt(Receipt::REJECTED, [$instanceId]);
        }

        return $this->send($instanceId, $event, $payload, $key ?? 'token:'.$activation, $by);
    }

    // Reading -----------------------------------------------------------------

    public function find(string $instanceId): ?InstanceView
    {
        $record = $this->kernel->store->findInstance($instanceId);
        if ($record !== null) {
            return $this->view($record);
        }
        $archived = $this->kernel->store->findArchived($instanceId);
        if ($archived === null) {
            return null;
        }
        $row = (array) ($archived['instance'] ?? []);
        $record = new InstanceRecord(
            id: $instanceId,
            definitionId: (string) ($row['definitionId'] ?? ''),
            definitionName: (string) ($row['definitionName'] ?? ''),
            definitionVersion: (int) ($row['definitionVersion'] ?? 0),
            status: (string) ($row['status'] ?? ''),
            snapshot: (array) ($row['snapshot'] ?? []),
            seq: (int) ($row['seq'] ?? 0),
            parentId: isset($row['parentId']) ? (string) $row['parentId'] : null,
            businessKey: isset($row['businessKey']) ? (string) $row['businessKey'] : null,
            subjectType: isset($row['subjectType']) ? (string) $row['subjectType'] : null,
            subjectId: isset($row['subjectId']) ? (string) $row['subjectId'] : null,
        );

        return new InstanceView($record, $this->kernel->definitions->load($record->definitionId), $this->kernel->store, $this->kernel->interpreter, $this->kernel->now(), $archived);
    }

    public function get(string $instanceId): InstanceView
    {
        return $this->find($instanceId) ?? throw InstanceNotFound::id($instanceId);
    }

    /**
     * @return list<InstanceView>
     */
    public function instances(InstanceQuery $query = new InstanceQuery): array
    {
        return array_map(fn (InstanceRecord $r) => $this->view($r), $this->kernel->store->queryInstances($query));
    }

    /**
     * Instances per active state of a process version: the overview of a map.
     *
     * @param  class-string<Process>|string  $process
     * @return array<string, int>
     */
    public function stateCounts(string $process, ?int $version = null): array
    {
        return $this->kernel->store->stateCounts($this->definitionRecord($process, $version)->id);
    }

    /**
     * @return list<IncidentRecord>
     */
    public function openIncidents(int $limit = 100): array
    {
        return $this->kernel->store->incidents(null, IncidentRecord::OPEN, $limit);
    }

    /**
     * Health numbers for monitoring.
     *
     * @return array<string, int>
     */
    public function health(): array
    {
        $store = $this->kernel->store;
        $now = $this->kernel->now();

        return [
            'running' => $store->countInstances(new InstanceQuery(statuses: [Snapshot::RUNNING])),
            'suspended' => $store->countInstances(new InstanceQuery(statuses: [Snapshot::SUSPENDED])),
            'with_incidents' => $store->countInstances(new InstanceQuery(hasIncidents: true)),
            'open_incidents' => count($store->incidents(null, IncidentRecord::OPEN, 10000)),
            'overdue_timers' => count($store->overdueTimers($now->modify('-'.Duration::seconds($this->kernel->options->overdueAfter).' seconds'), 10000)),
            'expired_effect_leases' => count($store->expiredEffects($now, 10000)),
        ];
    }

    // Operator actions --------------------------------------------------------

    /**
     * Move the instance to the given states regardless of guards.
     *
     * @param  list<string|\BackedEnum>  $states
     */
    public function transitionTo(string $instanceId, array $states, Actor $by, string $reason, bool $runExit = true, bool $runEntry = true): Receipt
    {
        $ids = array_map(fn ($s) => $s instanceof \BackedEnum ? (string) $s->value : $s, $states);

        return $this->operate($instanceId, 'transition', ['states' => $ids, 'runExit' => $runExit, 'runEntry' => $runEntry], $by, $reason);
    }

    /**
     * Change the context with an RFC 6902 patch.
     *
     * @param  list<array<string, mixed>>  $patch
     */
    public function patchContext(string $instanceId, array $patch, Actor $by, string $reason): Receipt
    {
        return $this->operate($instanceId, 'patch', ['patch' => $patch], $by, $reason);
    }

    public function suspend(string $instanceId, Actor $by, string $reason): Receipt
    {
        return $this->operate($instanceId, 'suspend', [], $by, $reason);
    }

    public function resume(string $instanceId, Actor $by, string $reason): Receipt
    {
        return $this->operate($instanceId, 'resume', [], $by, $reason);
    }

    /**
     * End the instance; with `compensate`, undo its compensable effects first.
     */
    public function cancel(string $instanceId, Actor $by, string $reason, bool $compensate = false): Receipt
    {
        return $this->operate($instanceId, 'cancel', ['compensate' => $compensate], $by, $reason);
    }

    /**
     * Run a failed or cancelled effect again. An at-most-once effect with an
     * unknown outcome needs `confirmUnsafe`: the operator takes responsibility.
     */
    public function retryEffect(string $effectId, Actor $by, string $reason, bool $confirmUnsafe = false): Receipt
    {
        $effect = $this->effect($effectId);

        return $this->operate($effect->instanceId, 'retry_effect', ['effect' => $effectId, 'confirmUnsafe' => $confirmUnsafe], $by, $reason);
    }

    /**
     * Close an effect by hand (after checking the other side): the process
     * gets the usual done or error event.
     */
    public function resolveEffect(string $effectId, Actor $by, string $reason, bool $succeeded = true, mixed $result = null, ?string $code = null): Receipt
    {
        $effect = $this->effect($effectId);

        return $this->operate($effect->instanceId, 'resolve_effect', [
            'effect' => $effectId, 'outcome' => $succeeded ? 'succeeded' : 'failed', 'result' => Hydrator::dehydrate($result), 'code' => $code,
        ], $by, $reason);
    }

    /**
     * Treat an effect as done without running it.
     */
    public function skipEffect(string $effectId, Actor $by, string $reason, mixed $result = null): Receipt
    {
        $effect = $this->effect($effectId);

        return $this->operate($effect->instanceId, 'skip_effect', ['effect' => $effectId, 'result' => Hydrator::dehydrate($result)], $by, $reason);
    }

    public function resolveIncident(string $incidentId, Actor $by, string $reason, string $resolution = 'resolved'): Receipt
    {
        $incident = $this->kernel->store->findIncident($incidentId) ?? throw new InvalidArgumentException("Incident {$incidentId} not found");

        return $this->operate($incident->instanceId, 'resolve_incident', ['incident' => $incidentId, 'resolution' => $resolution], $by, $reason);
    }

    /**
     * Process a failed event again (after fixing the code that broke its step).
     */
    public function redeliver(string $instanceId, int $inboxId, Actor $by, string $reason): Receipt
    {
        return $this->operate($instanceId, 'redeliver', ['inbox' => $inboxId], $by, $reason);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function operate(string $instanceId, string $operation, array $params, Actor $by, string $reason): Receipt
    {
        if (! in_array($operation, ManualOperations::OPERATIONS, true)) {
            throw new InvalidArgumentException("Unknown operation `{$operation}`");
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Operator actions need a reason');
        }
        $this->require($instanceId);
        $key = 'manual:'.Uuid::v7($this->kernel->now());
        $this->kernel->tx(fn () => $this->kernel->deliver($instanceId, 'manual.'.$operation, $params + ['reason' => $reason], $key, $by));

        return new Receipt(Receipt::ACCEPTED, [$instanceId]);
    }

    // Driving the runtime -----------------------------------------------------

    public function runInstance(string $instanceId): bool
    {
        return $this->runner->run($instanceId);
    }

    public function executeEffect(string $effectId): void
    {
        $this->executor->execute($effectId);
    }

    /**
     * @return array{timers: int, recovered: int, due: int, rerung: int, purged: int}
     */
    public function tick(int $limit = 500): array
    {
        return $this->ticker->tick($limit);
    }

    /**
     * Synchronous mode: work off everything the doorbell rang until idle.
     * Returns how many units of work ran.
     */
    public function drain(int $max = 10000): int
    {
        $dispatcher = $this->kernel->dispatcher;
        if (! $dispatcher instanceof SyncDispatcher) {
            throw new InvalidArgumentException('drain() needs the SyncDispatcher');
        }
        $done = 0;
        while ($done < $max) {
            $instance = $dispatcher->nextInstance();
            if ($instance !== null) {
                $this->runInstance($instance);
                $done++;

                continue;
            }
            $effect = $dispatcher->nextEffect();
            if ($effect !== null) {
                $this->executeEffect($effect);
                $done++;

                continue;
            }
            break;
        }

        return $done;
    }

    /**
     * Move finished instances older than `archiveAfter` into the archive.
     */
    public function archive(int $limit = 500): int
    {
        $store = $this->kernel->store;
        $before = $this->kernel->now()->modify('-'.Duration::seconds($this->kernel->options->archiveAfter).' seconds');
        $n = 0;
        foreach ($store->archivable($before, $limit) as $id) {
            $this->kernel->tx(function () use ($store, $id, &$n): void {
                $instance = $store->findInstance($id);
                if ($instance === null) {
                    return;
                }
                $store->archive($instance, [
                    'instance' => [
                        'definitionId' => $instance->definitionId,
                        'definitionName' => $instance->definitionName,
                        'definitionVersion' => $instance->definitionVersion,
                        'status' => $instance->status,
                        'snapshot' => $instance->snapshot,
                        'seq' => $instance->seq,
                        'parentId' => $instance->parentId,
                        'businessKey' => $instance->businessKey,
                        'subjectType' => $instance->subjectType,
                        'subjectId' => $instance->subjectId,
                        'createdAt' => $instance->createdAt?->format(DATE_RFC3339_EXTENDED),
                        'completedAt' => $instance->completedAt?->format(DATE_RFC3339_EXTENDED),
                    ],
                    'journal' => array_map(fn ($j) => $j->toArray(), $store->journal($id, 0, PHP_INT_MAX)),
                    'effects' => array_map(fn (EffectRecord $e) => [
                        'id' => $e->id, 'key' => $e->key, 'kind' => $e->kind, 'class' => $e->class, 'status' => $e->status,
                        'attempt' => $e->attempt, 'result' => $e->result, 'error' => $e->error,
                    ], $store->effects($id)),
                    'incidents' => array_map(fn (IncidentRecord $i) => [
                        'id' => $i->id, 'type' => $i->type, 'status' => $i->status, 'message' => $i->message, 'resolution' => $i->resolution,
                    ], $store->incidents($id, null, 10000)),
                ]);
                $n++;
            });
        }

        return $n;
    }

    // Internals ---------------------------------------------------------------

    private function view(InstanceRecord $record): InstanceView
    {
        return new InstanceView($record, $this->kernel->definitions->load($record->definitionId), $this->kernel->store, $this->kernel->interpreter, $this->kernel->now());
    }

    private function require(string $instanceId): InstanceRecord
    {
        return $this->kernel->store->findInstance($instanceId) ?? throw InstanceNotFound::id($instanceId);
    }

    private function effect(string $effectId): EffectRecord
    {
        return $this->kernel->store->findEffect($effectId) ?? throw new InvalidArgumentException("Effect {$effectId} not found");
    }

    /**
     * @param  class-string<Process>|string  $process
     */
    private function definitionRecord(string $process, ?int $version): DefinitionRecord
    {
        if (is_subclass_of($process, Process::class)) {
            if ($version === null || $version === $process::processVersion()) {
                return $this->kernel->definitions->forClass($process);
            }

            return $this->kernel->definitions->byName($process::processName(), $version);
        }

        return $this->kernel->definitions->byName($process, $version);
    }

    /**
     * @param  object|array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: class-string|null}
     */
    private static function contextData(object|array $context): array
    {
        if (is_array($context)) {
            return [$context, null];
        }
        $data = Hydrator::dehydrate($context);

        return [is_array($data) ? $data : [], $context::class];
    }

    /**
     * @internal for tests and adapters
     */
    public function stepContext(string $instanceId, int $seq = 0): StepContext
    {
        return new StepContext($this->kernel->now(), $instanceId, $seq);
    }

    /**
     * @internal
     *
     * @return list<TimerRecord>
     */
    public function timersDue(?DateTimeInterface $at = null): array
    {
        return $this->kernel->store->dueTimers(\DateTimeImmutable::createFromInterface($at ?? $this->kernel->now()), 1000);
    }
}
