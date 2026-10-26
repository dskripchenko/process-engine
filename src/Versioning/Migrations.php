<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Versioning;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Interpreter\Command;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Query\InstanceView;
use Dskripchenko\ProcessEngine\Runtime\Kernel;
use Dskripchenko\ProcessEngine\Runtime\Migrator;
use Dskripchenko\ProcessEngine\Runtime\Steps;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use InvalidArgumentException;

/**
 * Registered migrations and their application to one instance at a time,
 * under the instance lease (as a `manual.migrate` operation).
 *
 * @internal
 */
final class Migrations implements Migrator
{
    /** @var array<string, Migration> */
    private array $migrations = [];

    public function __construct(private readonly Kernel $k, private readonly Steps $steps) {}

    public function register(Migration $migration): void
    {
        $this->migrations[$migration->name()] = $migration;
    }

    public function get(string $name): Migration
    {
        return $this->migrations[$name] ?? throw new InvalidArgumentException("Unknown migration `{$name}`");
    }

    /**
     * Why the instance cannot be migrated now, or null when it can.
     */
    public function check(InstanceRecord $instance, Migration $migration): ?string
    {
        if ($instance->definitionName !== $migration->process || $instance->definitionVersion !== $migration->source) {
            return "it runs {$instance->definitionName} version {$instance->definitionVersion}";
        }
        if ($instance->isFinished()) {
            return "it is {$instance->status}";
        }
        if ($this->k->store->incidents($instance->id, IncidentRecord::OPEN) !== []) {
            return 'it has open incidents';
        }
        if ($this->k->store->effects($instance->id, [EffectRecord::RUNNING, EffectRecord::UNKNOWN, EffectRecord::RECONCILING]) !== []) {
            return 'effects are in flight or have an unknown outcome';
        }
        foreach ($this->k->store->children($instance->id) as $child) {
            if (! $child->isFinished() && isset($child->parentLink['invoke'])) {
                return 'invoked child processes are running';
            }
        }
        try {
            $target = $this->k->definitions->load($this->k->definitions->byName($migration->process, $migration->target())->id);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        foreach ($this->leaves($instance) as $state) {
            if ($migration->isDropped($state)) {
                return "it stands in dropped state `{$state}`";
            }
            $mapped = $migration->mapsTo($state);
            if ($mapped === null || ! $target->has($mapped)) {
                return "state `{$state}` has no counterpart in version {$migration->target()}";
            }
        }
        $view = new InstanceView($instance, $this->k->definitions->load($instance->definitionId), $this->k->store, $this->k->interpreter, $this->k->now());
        if (! $migration->accepts($view)) {
            return 'the migration condition does not hold';
        }

        return null;
    }

    public function apply(InstanceRecord $instance, array $params, Actor $actor, ?string $reason): string
    {
        $migration = $this->get((string) ($params['migration'] ?? ''));
        $problem = $this->check($instance, $migration);
        if ($problem !== null) {
            throw new InvalidArgumentException("Cannot migrate: {$problem}");
        }

        $oldDefinition = $this->k->definitions->load($instance->definitionId);
        $targetRecord = $this->k->definitions->byName($migration->process, $migration->target());
        $target = $this->k->definitions->load($targetRecord->id);
        $snapshot = Snapshot::fromArray($instance->snapshot);
        $leaves = $this->leaves($instance);
        $map = [];
        foreach ($leaves as $state) {
            $map[$state] = (string) $migration->mapsTo($state);
        }

        // Everything bound to the old activations ends: timers, polls, subscriptions, pending effects.
        $cancel = [];
        foreach ([...$snapshot->active, $oldDefinition->root->id] as $state) {
            $cancel[] = new Command(Command::CANCEL_ACTIVATION, ['prefix' => $instance->id.'/'.$snapshot->activation($state).'/', 'state' => $state]);
        }
        $this->steps->apply($instance, $oldDefinition, $cancel);

        $context = $migration->transform($snapshot->context);
        $result = $this->k->interpreter->adopt(
            $target,
            array_values(array_unique(array_values($map))),
            $context,
            $snapshot->contextClass,
            new StepContext($this->k->now(), $instance->id, $instance->seq + 1),
            $snapshot->activations,
        );

        $instance->definitionId = $targetRecord->id;
        $instance->definitionName = $targetRecord->name;
        $instance->definitionVersion = $targetRecord->version;
        $this->steps->commit($instance, $target, $result, null, $actor, $snapshot->context, $reason);
        $this->k->journal($instance->id, 'migrated', [
            'migration' => $migration->name(),
            'from' => $migration->source,
            'to' => $migration->target(),
            'states' => $map,
        ], $actor, $reason);

        return 'migrated to version '.$migration->target();
    }

    /**
     * @return list<string>
     */
    private function leaves(InstanceRecord $instance): array
    {
        $definition = $this->k->definitions->load($instance->definitionId);

        return array_values(array_filter($instance->activeStates(), fn (string $id) => $definition->has($id) && $definition->node($id)->isAtomic()));
    }
}
