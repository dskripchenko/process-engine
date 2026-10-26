<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Attributes\Semantics;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Effect\Compensable;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Interpreter\Command;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Interpreter\StepResult;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Store\DefinitionRecord;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Dskripchenko\ProcessEngine\Store\SubscriptionRecord;
use Dskripchenko\ProcessEngine\Store\TimerRecord;
use Dskripchenko\ProcessEngine\Support\Json;
use Dskripchenko\ProcessEngine\Support\JsonPatch;
use Dskripchenko\ProcessEngine\Support\Uuid;
use ReflectionClass;

/**
 * Turns interpreter results into stored facts, inside the caller's
 * transaction: creates instances, commits steps with fencing, writes the
 * journal and applies commands (effects, timers, polls, subscriptions,
 * children, cancellations, compensation, completion).
 *
 * @internal
 */
final class Steps
{
    public function __construct(private readonly Kernel $k) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $parentLink
     * @param  list<string>|null  $adoptStates  enter these states without entry actions
     */
    public function create(
        DefinitionRecord $record,
        array $context,
        ?string $contextClass,
        ?string $businessKey = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $parentId = null,
        ?string $parentKey = null,
        array $parentLink = [],
        ?Actor $actor = null,
        ?array $adoptStates = null,
        ?string $reason = null,
    ): InstanceRecord {
        $definition = $this->k->definitions->load($record->id);
        $id = Uuid::v7($this->k->now());
        $ctx = new StepContext($this->k->now(), $id, 1);
        $result = $adoptStates !== null
            ? $this->k->interpreter->adopt($definition, $adoptStates, $context, $contextClass, $ctx)
            : $this->k->interpreter->start($definition, $context, $contextClass, $ctx);
        $this->assertContextSize($result->snapshot);

        $instance = new InstanceRecord(
            id: $id,
            definitionId: $record->id,
            definitionName: $record->name,
            definitionVersion: $record->version,
            status: $result->snapshot->status,
            snapshot: $result->snapshot->toArray(),
            seq: 1,
            parentId: $parentId,
            parentKey: $parentKey,
            parentLink: $parentLink,
            businessKey: $businessKey,
            subjectType: $subjectType,
            subjectId: $subjectId,
            createdAt: $this->k->now(),
            updatedAt: $this->k->now(),
            completedAt: $result->snapshot->isFinished() ? $this->k->now() : null,
        );
        $this->k->store->insertInstance($instance);
        $this->k->journal($id, $adoptStates !== null ? 'adopted' : 'started', [
            'definition' => $record->name,
            'version' => $record->version,
            'context' => $context,
            'parent' => $parentId,
            'states' => $adoptStates,
        ], $actor, $reason);
        $this->journalStep($instance, $result, null, $actor, []);
        $this->apply($instance, $definition, $result->commands);
        $this->k->signal('instance.started', $id, ['definition' => $record->name, 'version' => $record->version]);

        return $instance;
    }

    /**
     * Store a step: snapshot (fenced by seq), journal, commands.
     *
     * @param  array<string, mixed>  $contextBefore
     */
    public function commit(InstanceRecord $instance, Definition $definition, StepResult $result, ?Event $event, ?Actor $actor, array $contextBefore, ?string $reason = null): void
    {
        $this->assertContextSize($result->snapshot);
        $expected = $instance->seq;
        $instance->snapshot = $result->snapshot->toArray();
        $instance->status = $result->snapshot->status;
        $instance->seq = $expected + 1;
        $instance->updatedAt = $this->k->now();
        if ($result->snapshot->isFinished() && $instance->completedAt === null) {
            $instance->completedAt = $this->k->now();
        }
        if (! $this->k->store->updateInstance($instance, $expected)) {
            throw new ConcurrencyConflict("Instance {$instance->id} moved past seq {$expected}");
        }
        $this->journalStep($instance, $result, $event, $actor, $contextBefore, $reason);
        $this->apply($instance, $definition, $result->commands);
        $this->k->signal('step.committed', $instance->id, ['event' => $event?->name, 'active' => $result->snapshot->active]);
    }

    /**
     * @param  array<string, mixed>  $contextBefore
     */
    public function journalStep(InstanceRecord $instance, StepResult $result, ?Event $event, ?Actor $actor, array $contextBefore, ?string $reason = null): void
    {
        $data = [
            'outcome' => $result->outcome,
            'event' => $event !== null ? ['name' => $event->name, 'payload' => $event->payload] : null,
            'transitions' => $result->transitions,
            'rejected' => $result->rejected,
            'exited' => $result->exited,
            'entered' => $result->entered,
            'active' => $result->snapshot->active,
            'context_patch' => JsonPatch::diff($contextBefore, $result->snapshot->context),
            'notes' => $result->notes,
            'commands' => array_map(fn (Command $c) => $c->type.(isset($c->data['class']) ? ':'.$c->data['class'] : (isset($c->data['process']) ? ':'.$c->data['process'] : '')), $result->commands),
        ];
        if ($result->reason !== null) {
            $data['reason'] = $result->reason;
        }
        if ($result->expected !== []) {
            $data['expected'] = $result->expected;
        }
        $type = match ($result->outcome) {
            StepResult::TRANSITIONED => 'step',
            StepResult::COLLECTED => 'event.collected',
            default => 'event.'.$result->outcome,
        };
        $this->k->journal($instance->id, $type, $data, $actor, $reason);

        if ($result->snapshot->isFinished()) {
            $this->k->journal($instance->id, $result->snapshot->status, ['final' => $result->snapshot->final, 'output' => $result->snapshot->output]);
            $this->k->signal('instance.'.$result->snapshot->status, $instance->id, ['final' => $result->snapshot->final]);
        }
    }

    /**
     * @param  list<Command>  $commands
     */
    public function apply(InstanceRecord $instance, Definition $definition, array $commands): void
    {
        foreach ($commands as $command) {
            $d = $command->data;
            switch ($command->type) {
                case Command::SCHEDULE_EFFECT:
                    $this->scheduleEffect($instance, $d);
                    break;
                case Command::START_TIMER:
                    $this->k->store->insertTimer(new TimerRecord(
                        id: Uuid::v7($this->k->now()),
                        instanceId: $instance->id,
                        key: (string) $d['key'],
                        event: (string) $d['event'],
                        fireAt: new DateTimeImmutable((string) $d['fireAt']),
                        activation: (string) $d['activation'],
                    ));
                    break;
                case Command::START_POLL:
                    $this->startPoll($instance, $d);
                    break;
                case Command::SUBSCRIBE:
                    foreach ((array) $d['events'] as $event) {
                        $this->k->store->insertSubscription(new SubscriptionRecord($instance->id, (string) $d['key'], (string) $event, (string) $d['correlationKey'], (string) $d['activation']));
                        foreach ($this->k->store->takeMessages((string) $event, (string) $d['correlationKey'], $this->k->now()) as $message) {
                            $this->k->deliver($instance->id, $message->event, $message->payload, 'publish:'.$message->key, Actor::fromArray($message->actor));
                        }
                    }
                    break;
                case Command::START_CHILD:
                    $this->startChild($instance, $d);
                    break;
                case Command::CANCEL_ACTIVATION:
                    $this->cancelActivation($instance, (string) $d['prefix']);
                    break;
                case Command::COMPENSATE:
                    $this->compensate($instance, isset($d['scope']) ? (string) $d['scope'] : null);
                    break;
                case Command::COMPLETE:
                    $this->notifyParent($instance, 'completed', isset($d['final']) ? (string) $d['final'] : null, $d['output'] ?? null);
                    break;
            }
        }
    }

    /**
     * End an instance and the children it owns. Children of an invocation
     * always end with it; spawned children only when their scope says so.
     */
    public function cancelTree(InstanceRecord $instance, ?Actor $actor, ?string $reason, bool $notifyParent): void
    {
        if ($instance->isFinished()) {
            return;
        }
        $definition = $this->k->definitions->load($instance->definitionId);
        $before = (array) ($instance->snapshot['context'] ?? []);
        $result = $this->k->interpreter->cancel($definition, Snapshot::fromArray($instance->snapshot), new StepContext($this->k->now(), $instance->id, $instance->seq + 1));
        $this->commit($instance, $definition, $result, null, $actor, $before, $reason);
        foreach ($this->k->store->children($instance->id) as $child) {
            $this->cancelTree($child, $actor, 'parent cancelled', false);
        }
        foreach ($this->k->store->effects($instance->id, [EffectRecord::PENDING, EffectRecord::WAITING]) as $effect) {
            $effect->status = EffectRecord::CANCELLED;
            $this->k->store->updateEffect($effect);
        }
        if ($notifyParent) {
            $this->notifyParent($instance, 'cancelled', null, null);
        }
    }

    public function notifyParent(InstanceRecord $child, string $status, ?string $final, mixed $output): void
    {
        if ($child->parentId === null) {
            return;
        }
        $parent = $this->k->store->findInstance($child->parentId);
        if ($parent === null || $parent->isFinished()) {
            return;
        }
        $link = $child->parentLink;
        $key = 'child:'.$child->id.':'.$status;
        if (isset($link['spawn'])) {
            $this->k->deliver($parent->id, 'done.spawn.'.$link['spawn'], [
                'key' => $child->parentKey, 'final' => $final, 'output' => $output, 'status' => $status, 'child' => $child->id,
            ], $key);

            return;
        }
        if (! isset($link['invoke'])) {
            return;
        }
        $invoke = (string) $link['invoke'];
        if ($status !== 'completed') {
            $this->k->deliver($parent->id, 'error.invoke.'.$invoke, [
                'activation' => $link['activation'] ?? null, 'code' => 'child_'.$status, 'message' => "Child process {$child->id} {$status}", 'child' => $child->id,
            ], $key);

            return;
        }
        if (($link['item'] ?? false) === true) {
            $this->k->deliver($parent->id, 'item.invoke.'.$invoke, [
                'activation' => $link['activation'] ?? null, 'index' => $link['index'] ?? 0, 'output' => $output, 'final' => $final, 'child' => $child->id,
            ], $key);

            return;
        }
        $this->k->deliver($parent->id, 'done.invoke.'.$invoke, [
            'activation' => $link['activation'] ?? null, 'output' => $output, 'final' => $final, 'child' => $child->id,
        ], $key);
    }

    // Commands ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $d
     */
    private function scheduleEffect(InstanceRecord $instance, array $d): void
    {
        $class = (string) $d['class'];
        $semantics = self::semantics($class);
        if ($semantics === null) {
            $this->k->incident($instance->id, IncidentRecord::HANDLER_MISSING, "Effect handler {$class} is missing or has no retry semantics", ['key' => $d['key']]);

            return;
        }
        $retry = isset($d['retry']) && is_array($d['retry']) ? Retry::fromArray($d['retry']) : $semantics->retry();

        $waiting = false;
        $concurrency = isset($d['concurrency']) ? (int) $d['concurrency'] : null;
        if ($concurrency !== null && isset($d['index'])) {
            $waiting = (int) $d['index'] >= $concurrency;
        }

        $effect = new EffectRecord(
            id: Uuid::v7($this->k->now()),
            instanceId: $instance->id,
            key: (string) $d['key'],
            kind: (string) ($d['kind'] ?? EffectRecord::KIND_INVOKE),
            class: $class,
            semantics: $semantics->name(),
            retry: $retry->toArray() + ['nonRetryable' => $semantics->nonRetryable()],
            status: $waiting ? EffectRecord::WAITING : EffectRecord::PENDING,
            input: [
                'context' => $d['context'] ?? [],
                'contextClass' => $d['contextClass'] ?? null,
                'input' => $d['input'] ?? null,
                'item' => $d['item'] ?? null,
                'index' => $d['index'] ?? null,
                'invoke' => $d['invoke'] ?? null,
                'activation' => $d['activation'] ?? null,
                'state' => $d['state'] ?? null,
                'scopes' => $d['scopes'] ?? [],
            ],
            nextAttemptAt: $this->k->now(),
            compensable: is_subclass_of($class, Compensable::class),
            createdAt: $this->k->now(),
        );
        if ($this->k->store->insertEffect($effect) && ! $waiting) {
            $this->k->ringEffect($effect->id);
        }
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function startPoll(InstanceRecord $instance, array $d): void
    {
        $class = (string) $d['class'];
        $semantics = self::semantics($class);
        if ($semantics === null) {
            $this->k->incident($instance->id, IncidentRecord::HANDLER_MISSING, "Poll handler {$class} is missing", ['key' => $d['key']]);

            return;
        }
        $next = new DateTimeImmutable((string) $d['nextAt']);
        $effect = new EffectRecord(
            id: Uuid::v7($this->k->now()),
            instanceId: $instance->id,
            key: (string) $d['key'],
            kind: EffectRecord::KIND_POLL,
            class: $class,
            semantics: $semantics->name(),
            retry: $semantics->retry()->toArray(),
            status: EffectRecord::PENDING,
            input: ['poll' => $d['poll'], 'activation' => $d['activation']],
            nextAttemptAt: $next,
            createdAt: $this->k->now(),
        );
        if ($this->k->store->insertEffect($effect) && $next <= $this->k->now()) {
            $this->k->ringEffect($effect->id);
        }
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function startChild(InstanceRecord $instance, array $d): void
    {
        $key = (string) $d['key'];
        if ($this->k->store->findChild($instance->id, $key) !== null) {
            return;
        }
        /** @var class-string<Process> $process */
        $process = (string) $d['process'];
        $record = $this->k->definitions->forClass($process);
        $input = is_array($d['input'] ?? null) ? $d['input'] : [];
        $inputClass = isset($d['inputClass']) && is_string($d['inputClass']) ? $d['inputClass'] : null;

        $child = $this->create($record, $input, $inputClass, parentId: $instance->id, parentKey: $key, parentLink: array_filter([
            'invoke' => $d['invoke'] ?? null,
            'spawn' => $d['spawn'] ?? null,
            'activation' => $d['activation'] ?? null,
            'index' => $d['index'] ?? null,
            'item' => $d['item'] ?? null,
            'onScopeExit' => $d['onScopeExit'] ?? 'cancel',
        ], fn ($v) => $v !== null));
        $this->k->journal($instance->id, 'child.started', ['child' => $child->id, 'process' => $record->name, 'version' => $record->version, 'key' => $key]);
    }

    private function cancelActivation(InstanceRecord $instance, string $prefix): void
    {
        foreach ($this->k->store->effects($instance->id, [EffectRecord::PENDING, EffectRecord::WAITING]) as $effect) {
            if (str_starts_with($effect->key, $prefix)) {
                $effect->status = EffectRecord::CANCELLED;
                $this->k->store->updateEffect($effect);
            }
        }
        $this->k->store->cancelTimers($instance->id, $prefix);
        $this->k->store->deleteSubscriptions($instance->id, $prefix);
        foreach ($this->k->store->children($instance->id) as $child) {
            if ($child->isFinished() || $child->parentKey === null || ! str_starts_with($child->parentKey, $prefix)) {
                continue;
            }
            if (($child->parentLink['onScopeExit'] ?? 'cancel') !== 'cancel') {
                continue;
            }
            $this->cancelTree($child, Actor::system(), 'scope exited', false);
        }
    }

    public function compensate(InstanceRecord $instance, ?string $scope): void
    {
        $inScope = fn (EffectRecord $e) => $e->kind !== EffectRecord::KIND_COMPENSATION
            && ($scope === null || in_array($scope, (array) ($e->input['scopes'] ?? []), true));

        $blocked = array_filter($this->k->store->effects($instance->id, [EffectRecord::UNKNOWN, EffectRecord::RECONCILING]), $inScope);
        if ($blocked !== []) {
            $this->k->incident($instance->id, IncidentRecord::COMPENSATION_BLOCKED, 'Compensation waits for effects with an unknown outcome', [
                'effects' => array_values(array_map(fn (EffectRecord $e) => $e->id, $blocked)),
                'scope' => $scope,
            ]);

            return;
        }

        $done = array_values(array_filter($this->k->store->effects($instance->id, [EffectRecord::SUCCEEDED]), fn (EffectRecord $e) => $e->compensable && $inScope($e)));
        usort($done, fn (EffectRecord $a, EffectRecord $b) => ($b->finishedAt?->format('U.u') ?? '') <=> ($a->finishedAt?->format('U.u') ?? '') ?: strcmp($b->id, $a->id));

        $run = 'run'.$instance->seq;
        if ($done === []) {
            $this->k->deliver($instance->id, 'done.compensation', ['compensated' => 0], 'compensation:'.$instance->id.':'.$run);

            return;
        }
        foreach ($done as $i => $original) {
            $effect = new EffectRecord(
                id: Uuid::v7($this->k->now()),
                instanceId: $instance->id,
                key: 'compensate:'.$original->key,
                kind: EffectRecord::KIND_COMPENSATION,
                class: $original->class,
                semantics: 'idempotent',
                retry: Retry::compensationDefault()->toArray(),
                status: $i === 0 ? EffectRecord::PENDING : EffectRecord::WAITING,
                input: $original->input + ['order' => $i, 'run' => $run, 'total' => count($done)],
                nextAttemptAt: $this->k->now(),
                compensationOf: $original->id,
                createdAt: $this->k->now(),
            );
            if ($this->k->store->insertEffect($effect) && $i === 0) {
                $this->k->ringEffect($effect->id);
            }
        }
        $this->k->journal($instance->id, 'compensation.started', ['scope' => $scope, 'effects' => array_map(fn ($e) => $e->id, $done)]);
    }

    private function assertContextSize(Snapshot $snapshot): void
    {
        $size = strlen(Json::encode($snapshot->context));
        if ($size > $this->k->options->maxContextBytes) {
            throw new ContextTooLarge("The context is {$size} bytes, more than {$this->k->options->maxContextBytes}: keep large data outside and store references");
        }
    }

    public static function semantics(string $class): ?Semantics
    {
        if (! class_exists($class)) {
            return null;
        }
        foreach ((new ReflectionClass($class))->getAttributes() as $attribute) {
            if (is_subclass_of($attribute->getName(), Semantics::class)) {
                /** @var Semantics */
                return $attribute->newInstance();
            }
        }

        return null;
    }
}
