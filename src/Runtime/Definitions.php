<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Builder\Compiler;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Store\DefinitionRecord;
use Dskripchenko\ProcessEngine\Store\InstanceQuery;
use Dskripchenko\ProcessEngine\Store\Store;

/**
 * Stored definitions: registers the version a Process class declares
 * (immutable: a different graph under the same version is an error) and
 * loads stored versions for execution, whether or not their code still exists.
 */
final class Definitions
{
    /** @var array<string, Definition> */
    private array $loaded = [];

    /** @var array<string, DefinitionRecord> */
    private array $registered = [];

    public function __construct(
        private readonly Store $store,
        private readonly Clock $clock,
        private readonly bool $allowRedefineUnused = false,
    ) {}

    /**
     * The current code version of a process class, registered on first use.
     *
     * @param  class-string<Process>  $class
     */
    public function forClass(string $class): DefinitionRecord
    {
        if (isset($this->registered[$class])) {
            return $this->registered[$class];
        }

        return $this->registered[$class] = $this->register(Compiler::compile($class));
    }

    public function register(Definition $definition): DefinitionRecord
    {
        $existing = $this->store->findDefinition($definition->name(), $definition->version());
        if ($existing !== null) {
            if ($existing->hash === $definition->hash()) {
                $this->loaded[$existing->id] = $definition;

                return $existing;
            }
            $used = $this->store->countInstances(new InstanceQuery(definitionName: $definition->name(), definitionVersion: $definition->version(), limit: 1)) > 0;
            if (! $this->allowRedefineUnused || $used) {
                throw new DefinitionVersionConflict(sprintf(
                    'Process `%s` version %d is already stored with a different graph: bump VERSION (stored %s, now %s)',
                    $definition->name(),
                    $definition->version(),
                    substr($existing->hash, 0, 12),
                    substr($definition->hash(), 0, 12),
                ));
            }
            $this->store->deleteDefinition($existing->id);
            unset($this->loaded[$existing->id]);
        }

        $record = $this->store->insertDefinition(new DefinitionRecord(
            id: '',
            name: $definition->name(),
            version: $definition->version(),
            hash: $definition->hash(),
            schema: $definition->toArray(),
            createdAt: $this->clock->now(),
        ));
        $this->loaded[$record->id] = $definition;

        return $record;
    }

    public function record(string $id): DefinitionRecord
    {
        return $this->store->findDefinitionById($id) ?? throw new \RuntimeException("Definition `{$id}` is not stored");
    }

    /**
     * The definition as stored: executes even when its class has moved on.
     */
    public function load(string $id): Definition
    {
        if (isset($this->loaded[$id])) {
            return $this->loaded[$id];
        }

        return $this->loaded[$id] = Definition::fromArray($this->record($id)->schema);
    }

    /**
     * The stored record for a name: a given version, or the latest.
     */
    public function byName(string $name, ?int $version = null): DefinitionRecord
    {
        $record = $version !== null ? $this->store->findDefinition($name, $version) : $this->store->latestDefinition($name);

        return $record ?? throw new \RuntimeException("Process `{$name}`".($version !== null ? " version {$version}" : '').' is not registered');
    }
}
