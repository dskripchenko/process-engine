# Effects and retry semantics

An **effect** is anything that touches the outside world: an API call, a
payment, an e-mail, a generated document. Effects never run inside a step:
the step stores them in an outbox, and they run after the step committed,
outside any transaction or lock. Their outcome comes back to the process as
an event.

## Semantics are mandatory

```php
use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
```

| Attribute | Guarantee | When the outcome is in doubt |
|---|---|---|
| `#[Idempotent]` | runs at least once | retried under its policy |
| `#[AtMostOnce]` | runs at most once per key | becomes `unknown`: no automatic retry |

There is no default. An effect class without one of them is rejected when the
process is built — the author has to decide whether repeating the call is
safe. Sending an e-mail twice is a nuisance; charging a card twice is not.

## Writing an effect

```php
#[AtMostOnce]
final class SubmitToInsurer implements Reconcilable
{
    public function __construct(private readonly InsurerApi $api) {}  // any services

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        try {
            return $this->api->submit($claim->application, idempotencyKey: $fx->key)->id;
        } catch (ConnectionRefused $e) {
            throw new EffectNotExecuted($e);   // never reached the other side
        }
    }

    public function reconcile(ClaimContext $claim, Effect $fx): Outcome
    {
        $found = $this->api->findByIdempotencyKey($fx->key);

        return $found ? Outcome::succeeded($found->id) : Outcome::stillUnknown();
    }
}
```

Arguments are injected by type: the context (object or `array $context`),
`Effect $fx`, and `$item` / `$input` / `$result` by name. `Effect` carries:

| Field | |
|---|---|
| `key` | the idempotency key: the same across retries of one call, new for a new activation of the state |
| `instanceId`, `attempt`, `now` | |
| `item`, `index` | the element of a `forEach` |
| `input` | what `invoke(..., input:)` built |

**Pass `$fx->key` to the other side** wherever it supports idempotency keys.
It is what makes reconciliation possible.

## How an outcome is classified

| The handler… | Result |
|---|---|
| returns a value | `succeeded`; the process gets `done.invoke.<id>` with `output` |
| throws `EffectNotExecuted` | the call certainly did not happen: retried (any semantics) while attempts last |
| throws `EffectFailed($code)` | the call happened and was refused: `failed`, `error.invoke.<id>` with the code, no retry |
| throws anything else, times out, or its worker dies | ambiguous: `#[Idempotent]` retries, `#[AtMostOnce]` becomes `unknown` |

Rules of thumb for HTTP clients: connection refused or DNS failure before
sending → `EffectNotExecuted`; a 4xx business answer → `EffectFailed`; a read
timeout or a 5xx after sending → let it propagate (ambiguous).

## At most once, precisely

1. The effect is claimed: `running`, attempt + 1, a lease. This "started"
   mark is committed **before** the call.
2. The handler runs.
3. The result is committed with the event for the process.

If the worker dies between 1 and 3, the lease expires and the tick marks the
effect `unknown` — never `pending`. The process gets `unknown.invoke.<id>`.

Then:
- with `Reconcilable`, `reconcile()` runs on its own policy (by default 10
  attempts, 1 minute → 6 hours). `succeeded`/`failed` close the effect as if
  the call had returned; `notExecuted` allows one more real call;
  `stillUnknown` tries again later and, when attempts run out, opens an
  incident;
- without it, an incident is opened at once.

The process waits in the state (or goes to `onUnknown:` if declared). An
operator settles it with `resolveEffect()` after checking the other side, or
reruns it with `retryEffect(..., confirmUnsafe: true)`, taking responsibility
in the journal.

## Retry policies

```php
use Dskripchenko\ProcessEngine\Effect\Retry;

#[Idempotent(
    retry: new Retry(attempts: 5, initialInterval: '10 seconds', backoff: 2.0, maxInterval: '1 hour',
                     jitter: true, attemptTimeout: '2 minutes', onExhausted: 'error'),
    nonRetryable: [ValidationError::class],
)]
```

| Field | Default (idempotent / at most once) |
|---|---|
| `attempts` | 5 / 3 (only `EffectNotExecuted` retries an at-most-once effect) |
| `initialInterval`, `backoff`, `maxInterval`, `jitter` | 10 s, ×2, 1 h, on |
| `attemptTimeout` | 2 minutes: the lease of one attempt; a longer call is considered dead |
| `onExhausted` | `error`: `error.invoke.<id>` with code `retries_exhausted` (unhandled → incident); `incident`: an incident, the process waits; `fail`: the instance fails |

`invoke(..., retry: new Retry(...))` overrides the policy at one place in a
graph; the semantics stay those of the class.

## Results and errors in the graph

```php
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing', onError: [
    'bad_template' => 'manualCheck',
    '*'            => 'failed',
])
```

An effect can also return `Publish::to($correlationKey, $event)` or
`Send::to($instanceId, $event)` (or a list of them) to deliver events to other
instances — see [events](events-and-timers.md).

Effects in `entry()`, `exit()` and `run:` are fire-and-forget: their outcome
does not move the process, but they follow the same semantics, and an effect
that finally fails opens an incident.

## Compensation (saga)

An effect that can be undone implements `Compensable`:

```php
#[AtMostOnce]
final class PayOut implements Compensable, Reconcilable
{
    public function __invoke(ClaimContext $claim, Effect $fx): string { /* transfer */ }
    public function reconcile(Effect $fx): Outcome { /* look up by $fx->key */ }
    public function compensate(mixed $result): void { /* refund the transfer $result */ }
}
```

```php
$p->state('cancelling', fn (Builder $s) => $s->compensate(onDone: 'cancelled'));
// or for one stage:
$p->state('undoBooking', fn (Builder $s) => $s->compensate(onDone: 'choosingSlot', scope: 'agreement'));
```

- On entry, the completed compensable effects (of the process, or of the
  given state) are undone **in reverse order of completion, one by one**.
- `compensate()` receives the original result; it is retried (20 attempts by
  default) and then opens an incident.
- An effect in `unknown` inside the scope **blocks** compensation (with an
  incident): undoing something that may not have happened is wrong.
- `Engine::cancel($id, ..., compensate: true)` compensates the whole process,
  then cancels it.

## Polls

A poll is a periodic idempotent effect bound to a state; see
[events](events-and-timers.md#polling).
