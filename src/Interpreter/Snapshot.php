<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

/**
 * The state of one instance between steps: the active states, the context,
 * activation counters, history memory and collected results of forEach calls.
 */
final class Snapshot
{
    public const RUNNING = 'running';

    public const SUSPENDED = 'suspended';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * @param  list<string>  $active  every active state (compound ones included), the root excluded
     * @param  array<string, mixed>  $context
     * @param  array<string, int>  $activations  state id → number of entries
     * @param  array<string, list<string>>  $history  history state id → remembered states
     * @param  array<string, array<string, mixed>>  $invocations  forEach key → {total, outputs}
     * @param  array<string, string>  $entered  state id → entry time (ISO 8601)
     */
    public function __construct(
        public array $active = [],
        public array $context = [],
        public ?string $contextClass = null,
        public array $activations = [],
        public array $history = [],
        public array $invocations = [],
        public array $entered = [],
        public string $status = self::RUNNING,
        public ?string $final = null,
        public mixed $output = null,
    ) {}

    public function isActive(string $state): bool
    {
        return in_array($state, $this->active, true);
    }

    public function activation(string $state): string
    {
        return $state.'@'.($this->activations[$state] ?? 0);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active' => array_values($this->active),
            'context' => $this->context,
            'contextClass' => $this->contextClass,
            'activations' => $this->activations,
            'history' => $this->history,
            'invocations' => $this->invocations,
            'entered' => $this->entered,
            'status' => $this->status,
            'final' => $this->final,
            'output' => $this->output,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            active: array_values(array_map('strval', (array) ($data['active'] ?? []))),
            context: (array) ($data['context'] ?? []),
            contextClass: isset($data['contextClass']) ? (string) $data['contextClass'] : null,
            activations: array_map('intval', (array) ($data['activations'] ?? [])),
            history: (array) ($data['history'] ?? []),
            invocations: (array) ($data['invocations'] ?? []),
            entered: array_map('strval', (array) ($data['entered'] ?? [])),
            status: (string) ($data['status'] ?? self::RUNNING),
            final: isset($data['final']) ? (string) $data['final'] : null,
            output: $data['output'] ?? null,
        );
    }
}
