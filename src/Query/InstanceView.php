<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Query;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Interpreter\ExpectedEvent;
use Dskripchenko\ProcessEngine\Interpreter\Interpreter;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Dskripchenko\ProcessEngine\Store\JournalRecord;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Store\SubscriptionRecord;
use Dskripchenko\ProcessEngine\Store\TimerRecord;
use Dskripchenko\ProcessEngine\Support\Hydrator;

/**
 * Everything about one instance an interface or an operator needs: where it
 * is, what it waits for, what runs, what went wrong, how it got here.
 * Works the same for archived instances (read-only).
 */
final class InstanceView
{
    private ?Snapshot $snapshot = null;

    /**
     * @param  array<string, mixed>|null  $archived  the archive document
     */
    public function __construct(
        private readonly InstanceRecord $record,
        private readonly Definition $definition,
        private readonly Store $store,
        private readonly Interpreter $interpreter,
        private readonly DateTimeImmutable $now,
        private readonly ?array $archived = null,
    ) {}

    public function id(): string
    {
        return $this->record->id;
    }

    public function process(): string
    {
        return $this->record->definitionName;
    }

    public function version(): int
    {
        return $this->record->definitionVersion;
    }

    public function definition(): Definition
    {
        return $this->definition;
    }

    public function record(): InstanceRecord
    {
        return $this->record;
    }

    /** running | suspended | completed | failed | cancelled */
    public function status(): string
    {
        return $this->record->status;
    }

    public function isFinished(): bool
    {
        return $this->record->isFinished();
    }

    public function isArchived(): bool
    {
        return $this->archived !== null;
    }

    public function businessKey(): ?string
    {
        return $this->record->businessKey;
    }

    /**
     * @return array{type: string|null, id: string|null}
     */
    public function subject(): array
    {
        return ['type' => $this->record->subjectType, 'id' => $this->record->subjectId];
    }

    public function parentId(): ?string
    {
        return $this->record->parentId;
    }

    public function snapshot(): Snapshot
    {
        return $this->snapshot ??= Snapshot::fromArray($this->record->snapshot);
    }

    /**
     * Every active state, from the outermost down, in document order.
     *
     * @return list<string>
     */
    public function active(): array
    {
        return $this->snapshot()->active;
    }

    /**
     * The innermost active states (where the instance "stands").
     *
     * @return list<string>
     */
    public function leaves(): array
    {
        return array_values(array_filter($this->active(), fn (string $id) => $this->definition->has($id) && $this->definition->node($id)->isAtomic()));
    }

    public function is(string|\BackedEnum $state): bool
    {
        $id = $state instanceof \BackedEnum ? (string) $state->value : $state;

        return in_array($id, $this->active(), true) || ($this->isFinished() && $this->final() === $id);
    }

    /**
     * When each active state was entered (ISO 8601).
     *
     * @return array<string, string>
     */
    public function enteredAt(): array
    {
        return $this->snapshot()->entered;
    }

    /**
     * @return array<string, mixed>
     */
    public function contextArray(): array
    {
        return $this->snapshot()->context;
    }

    /**
     * The context as the object the instance was started with, or the array.
     *
     * @return object|array<string, mixed>
     */
    public function context(): object|array
    {
        $class = $this->snapshot()->contextClass;
        if ($class !== null && class_exists($class)) {
            return Hydrator::hydrate($class, $this->snapshot()->context);
        }

        return $this->snapshot()->context;
    }

    public function final(): ?string
    {
        return $this->snapshot()->final;
    }

    public function output(): mixed
    {
        return $this->snapshot()->output;
    }

    /**
     * The client-facing status: `meta.label` of the innermost labelled state
     * (one per active leaf in parallel regions), or of the final state.
     *
     * @return string|list<string>|null
     */
    public function label(): string|array|null
    {
        $labels = $this->labels();

        return match (count($labels)) {
            0 => null,
            1 => $labels[0],
            default => $labels,
        };
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        $ids = $this->isFinished() && $this->final() !== null ? [$this->final()] : $this->leaves();
        $labels = [];
        foreach ($ids as $id) {
            if (! $this->definition->has($id)) {
                continue;
            }
            for ($node = $this->definition->node($id); $node !== null; $node = $node->parent) {
                if ($node->label() !== null) {
                    $labels[] = $node->label();
                    break;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Events the instance accepts now (guards on the context already
     * evaluated), plus correlated and timer events.
     *
     * @return list<ExpectedEvent>
     */
    public function expects(): array
    {
        if ($this->isArchived()) {
            return [];
        }
        $expected = $this->interpreter->expects($this->definition, $this->snapshot(), new StepContext($this->now, $this->record->id, $this->record->seq));
        $timers = [];
        foreach ($this->timers() as $timer) {
            $timers[$timer->event] = $timer;
        }
        $out = [];
        foreach ($expected as $event) {
            if ($event->kind === 'timer') {
                if (isset($timers[$event->event])) {
                    $out[] = new ExpectedEvent($event->event, 'at '.$timers[$event->event]->fireAt->format(DATE_ATOM), null, $event->state, false, 'timer');
                }

                continue;
            }
            $out[] = $event;
        }

        return $out;
    }

    /**
     * @return list<TimerRecord>
     */
    public function timers(): array
    {
        if ($this->archived !== null) {
            return [];
        }

        return $this->store->timers($this->record->id, TimerRecord::PENDING);
    }

    /**
     * Pending timers past their moment: a sign the ticker is not running.
     *
     * @return list<TimerRecord>
     */
    public function overdueTimers(): array
    {
        return array_values(array_filter($this->timers(), fn (TimerRecord $t) => $t->fireAt < $this->now));
    }

    /**
     * @return list<SubscriptionRecord>
     */
    public function subscriptions(): array
    {
        return $this->archived !== null ? [] : $this->store->subscriptions($this->record->id);
    }

    /**
     * @param  list<string>|null  $statuses
     * @return list<EffectRecord>
     */
    public function effects(?array $statuses = null): array
    {
        if ($this->archived !== null) {
            return [];
        }

        return $this->store->effects($this->record->id, $statuses);
    }

    /**
     * Effects in flight or waiting for a decision.
     *
     * @return list<EffectRecord>
     */
    public function pendingEffects(): array
    {
        return $this->effects([EffectRecord::PENDING, EffectRecord::RUNNING, EffectRecord::UNKNOWN, EffectRecord::RECONCILING, EffectRecord::WAITING]);
    }

    /**
     * @return list<IncidentRecord>
     */
    public function incidents(?string $status = IncidentRecord::OPEN): array
    {
        if ($this->archived !== null) {
            return [];
        }

        return $this->store->incidents($this->record->id, $status);
    }

    /**
     * @return list<InboxRecord>
     */
    public function inbox(?string $status = null): array
    {
        return $this->archived !== null ? [] : $this->store->inbox($this->record->id, $status);
    }

    /**
     * @return list<JournalRecord>
     */
    public function journal(int $afterSeq = 0, int $limit = 1000): array
    {
        if ($this->archived !== null) {
            $entries = [];
            foreach ((array) ($this->archived['journal'] ?? []) as $row) {
                $row = (array) $row;
                $entries[] = new JournalRecord($this->record->id, (string) $row['type'], (array) ($row['data'] ?? []), new DateTimeImmutable((string) $row['at']), isset($row['actor']) ? (array) $row['actor'] : null, isset($row['reason']) ? (string) $row['reason'] : null, (int) ($row['seq'] ?? 0));
            }

            return array_slice(array_values(array_filter($entries, fn ($e) => $e->seq > $afterSeq)), 0, $limit);
        }

        return $this->store->journal($this->record->id, $afterSeq, $limit);
    }

    /**
     * Why the instance is where it is: the last journal entry that moved it.
     */
    public function why(): ?JournalRecord
    {
        $last = null;
        foreach ($this->journal() as $entry) {
            if (in_array($entry->type, ['started', 'step', 'adopted', 'migrated', 'manual.transition'], true)) {
                $last = $entry;
            }
        }

        return $last;
    }

    /**
     * @return list<InstanceRecord>
     */
    public function children(): array
    {
        return $this->archived !== null ? [] : $this->store->children($this->record->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'process' => $this->process(),
            'version' => $this->version(),
            'status' => $this->status(),
            'archived' => $this->isArchived(),
            'active' => $this->active(),
            'label' => $this->label(),
            'final' => $this->final(),
            'output' => $this->output(),
            'context' => $this->contextArray(),
            'expects' => array_map(fn (ExpectedEvent $e) => $e->toArray(), $this->expects()),
            'incidents' => count($this->incidents()),
            'businessKey' => $this->businessKey(),
            'subject' => $this->subject(),
            'parent' => $this->parentId(),
            'createdAt' => $this->record->createdAt?->format(DATE_ATOM),
            'updatedAt' => $this->record->updatedAt?->format(DATE_ATOM),
        ];
    }
}
