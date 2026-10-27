<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

/**
 * Something the runtime must do in the outside world after the step commits.
 * Commands are data: the step stays a pure function.
 */
final class Command
{
    /** Schedule an effect: {key, class, kind, context, contextClass, input?, item?, index?, invoke?, activation?, retry?, state?} */
    public const SCHEDULE_EFFECT = 'schedule_effect';

    /** {key, timer, event, fireAt, activation} */
    public const START_TIMER = 'start_timer';

    /** {key, poll, class, nextAt, activation, context, contextClass} */
    public const START_POLL = 'start_poll';

    /** {key, events, correlationKey, activation} */
    public const SUBSCRIBE = 'subscribe';

    /** {key, process, input, inputClass?, invoke?, spawn?, activation, item?, index?, onScopeExit} */
    public const START_CHILD = 'start_child';

    /** Switch a switchable state off (ADR-015): its invocation failed with an `offOn` code. */
    public const SWITCH_OFF = 'switch_off';

    /** Start a batch invocation (ADR-014). */
    public const START_BATCH = 'start_batch';

    /**
     * Everything bound to an activation of a state ends with it: pending
     * effects of its invocations, timers, polls, subscriptions and children
     * (spawned ones only when `onScopeExit` is `cancel`). {prefix, state}
     */
    public const CANCEL_ACTIVATION = 'cancel_activation';

    /** Undo completed compensable effects: {scope|null} */
    public const COMPENSATE = 'compensate';

    /** The process reached a top-level final: {final, output} */
    public const COMPLETE = 'complete';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
    ) {}

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }
}
