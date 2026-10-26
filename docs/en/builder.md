# Defining processes

## The process class

```php
use Dskripchenko\ProcessEngine\Attributes\ProcessName;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

#[ProcessName('claim-settlement')]        // optional; default: kebab-case class name
final class ClaimSettlement extends Process
{
    public const VERSION = 1;               // required; bump it whenever the graph changes
    public const STATES = ClaimState::class; // optional: an enum of state ids, checked against the graph

    public function define(Builder $p): void { /* the graph */ }

    public function projections(): array { return []; } // optional, see the Laravel bridge
}
```

The class is instantiated **without constructor arguments**. Guards and
actions therefore cannot reach services or do I/O; anything external is an
effect. This is what keeps a step a pure function.

## Rules the builder applies

- State ids are unique within the process; targets are written by id.
- The first child declared is the initial state (override with `initial()`).
- Guards and actions are **methods of the process** written as
  `$this->method(...)`, or declarative predicates and actions. Anonymous
  closures are rejected: they cannot be stored or versioned.
- Effects are classes with `#[Idempotent]` or `#[AtMostOnce]`; a class
  without retry semantics is rejected.
- State ids may be strings or backed enum cases (`ClaimState::Review`).

`define()` is compiled into a canonical array on first use. Errors (unknown
targets, missing semantics, unknown methods, child finals not routed) fail
the build with every problem listed; warnings point at unreachable states
and choices that may get stuck.

## States

```php
$p->state('review', fn (Builder $s) => $s /* … */);   // atomic, or compound with children
$p->parallel('agreement', fn (Builder $s) => $s
    ->region('money', fn (Builder $r) => $r /* … */)
    ->region('service', fn (Builder $r) => $r /* … */), join: 'all'); // or 'any'
$p->final('closed', output: 'payoutId');              // a context property or a method
$p->choice('route')->when($this->isMoney(...), 'payout')->otherwise('finalizing');
$p->history('resume', deep: true);                    // inside a compound state
```

See [structure](structure.md) for nesting, parallel regions, history and
child processes.

## Transitions

```php
->on('client.signed', to: 'review')
->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...))
->on(PartyDecision::class, to: 'rejected', run: $this->storeReason(...))   // first matching wins
->on('client.edit', run: $this->touch(...))                                 // no target: internal
->always(to: 'next', when: $this->ready(...))                               // eventless
->after('10 days', to: 'reminded')                                          // timer, see events
->onDone(to: 'agreement')                                                   // the compound reached its final
```

Parameters of `on()`:

| Parameter | Meaning |
|---|---|
| `to:` | target state; without it the transition is internal (no exit/entry) |
| `when:` | guard: a method returning `bool`, or a predicate |
| `run:` | actions: methods, effect classes, `assign()`/`raise()`/`note()`, or a list |
| `label:` | a label for interfaces (`expects()`, buttons) |
| `from:` | only on the process: source states, or `'*'` for any unfinished state |
| `match:` + `targets:` + `otherwise:` | a routing table on an event field |
| `spawn:` + `input:` + `key:` | start a child process per event (see structure) |

### Routing tables

```php
->on(ClaimStatusChanged::class, match: 'status', targets: [
    'upload_documents' => 'awaitingDocuments',
    'payment'          => 'paying',
    'done'             => 'settled',
], otherwise: 'reviewing')

$p->choice('kind')->match('scenario', ['documents' => 'documents', 'inspection' => 'inspection'], otherwise: 'info');
```

Each value becomes an ordinary guarded transition, so the graph stays
explicit and drawable.

### From any state

```php
$p->on('client.cancelled', to: 'cancelling', from: ['application', 'review']);
$p->on('client.cancelled', to: 'cancelled', from: '*');
```

## Guards and actions as methods

Arguments are injected **by type and name**:

| Parameter | Receives |
|---|---|
| the context class (`ClaimContext $c`) | the context object (changes are saved) |
| `array $context` / `array &$context` | the context array (by reference to change it) |
| an event class (`PartyDecision $e`) | the event rebuilt from its payload |
| `array $event` | the event payload |
| `StepContext $step` | the time of the step, the instance id, `isIn()`, `spawned()` |
| others | extras (the element of a `forEach`, …) |

```php
private function isApproved(PartyDecision $e): bool { return $e->approved; }
private function countRequest(ClaimContext $c): void { $c->documentRequests++; }
private function sla(array $context): string { return $context['vip'] ? '1 hour' : '1 day'; }
```

## The context

An array, or an object with public properties (promoted constructor
parameters work). Supported types: scalars, arrays, backed enums,
`DateTimeImmutable`, nested objects; a list of objects needs
`#[ListOf(Item::class)]`. Keep large data (files, documents) outside and
store references. The default size limit is 256 KB of JSON.

Effects never change the context directly: they return a result, stored
with `into:` or used by an `onDone` method. That keeps every context change
inside a step transaction.

## Declarative predicates and actions

For definitions loaded from JSON, or short guards:

```php
use function Dskripchenko\ProcessEngine\{eq, gt, in, exists, all, any, not, flag, assign, fromEvent, inc, raise, note};

->on('pay', to: 'paid', when: all(gt('$.event.amount', 0), not(exists('blocked'))))
->on('edit', run: assign('form', fromEvent('form')))
->on('retry', run: [assign('attempts', inc(1)), note('retried by the client')])
->on('fast', to: 'express', when: flag('express-lane'))   // a host feature switch
```

Paths without `$.` are context properties (`'amount'` is `$.context.amount`).
Operators: `eq ne gt gte lt lte in exists empty all any not flag`.

## Activities of a state

```php
->entry(NotifyClient::class, $this->stamp(...))   // on entry: effects and methods
->exit(note('left review'))
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing')   // lives while the state is active
->poll(CheckSignature::class, every: '1 minute')                       // see events
->correlate([PartyDecision::class], by: 'externalId')                  // see events
->defer(PartyDecision::class)                                          // keep until a state accepts it
->compensate(onDone: 'cancelled')                                      // see effects
->meta(label: 'Under review', description: 'The insurer checks the claim')
```

### `invoke()` options

| Option | Meaning |
|---|---|
| `into:` | context property that receives the result |
| `input:` | a method building the input (`Effect::$input`, or the child's context) |
| `forEach:` | a context list: one call per element, results collected in order |
| `concurrency:` | at most this many calls of a `forEach` at a time |
| `onDone:` | target; for a child process, a map final state → target |
| `onError:` | target, or a map error code → target (`'*'` for the rest) |
| `onUnknown:` | where to go when an at-most-once outcome is unknown (default: wait) |
| `retry:` | a `Retry` policy overriding the effect's default |

## Chains of effects

```php
use function Dskripchenko\ProcessEngine\step;

$p->steps('registration', [
    step(AddParticipant::class, forEach: 'participants', into: 'participantIds'),
    step(CreateClaims::class, into: 'claims'),
    step(AttachDocument::class, forEach: 'documents', concurrency: 5),
], onDone: 'settlement', onError: 'registrationFailed');
```

Each step becomes a state of its own (visible in the journal and in flow),
moving to the next when its effect is done.

## Labels for interfaces

`meta(label:)` on states and `label:` on transitions are not read by the
engine. `InstanceView::label()` returns the label of the innermost labelled
active state; `expects()` returns the events accepted now with their labels —
the buttons a client may press.
