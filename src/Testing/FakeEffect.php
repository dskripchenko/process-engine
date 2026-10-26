<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Testing;

use Closure;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Runtime\FaultInjector;
use Throwable;

/**
 * Stands in for an effect handler: records calls and answers with a value,
 * a callback, an exception, or a simulated crash.
 */
final class FakeEffect
{
    /** @var list<Effect> */
    public array $calls = [];

    /** @var list<Effect> */
    public array $reconciles = [];

    /** @var list<Effect> */
    public array $compensations = [];

    /**
     * @param  list<mixed>  $sequence  answers for consecutive calls (a Throwable is thrown)
     */
    public function __construct(
        private readonly FaultInjector $faults,
        public mixed $returns = null,
        public ?Throwable $throws = null,
        public ?string $crash = null,
        public array $sequence = [],
        public ?Closure $using = null,
        public Outcome|Throwable|null $reconcile = null,
        public ?Throwable $compensateThrows = null,
    ) {}

    public function __invoke(Effect $fx): mixed
    {
        $this->calls[] = $fx;
        if ($this->crash !== null) {
            $point = match ($this->crash) {
                'after-call', 'after_call' => 'effect.after_call',
                'after-record', 'after_record' => 'effect.after_record',
                default => $this->crash,
            };
            $this->crash = null;
            $this->faults->crashAt($point);
        }
        if ($this->sequence !== []) {
            $answer = array_shift($this->sequence);
            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return $answer;
        }
        if ($this->throws !== null) {
            throw $this->throws;
        }
        if ($this->using !== null) {
            return ($this->using)($fx);
        }

        return $this->returns;
    }

    public function reconcile(Effect $fx): Outcome
    {
        $this->reconciles[] = $fx;
        if ($this->reconcile instanceof Throwable) {
            throw $this->reconcile;
        }

        return $this->reconcile ?? Outcome::stillUnknown();
    }

    public function compensate(Effect $fx): void
    {
        $this->compensations[] = $fx;
        if ($this->compensateThrows !== null) {
            throw $this->compensateThrows;
        }
    }
}
