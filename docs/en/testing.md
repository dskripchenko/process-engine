# Testing processes

`ProcessTest` runs a process on an in-memory engine with a virtual clock,
fake effects and fault injection, synchronously. It is the harness the
engine's own test suite is built on.

```php
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

it('settles a repair claim after a document request', function () {
    ProcessTest::start(ClaimSettlement::class, new ClaimContext(policyId: 'P-1'), fakes: [
        RenderApplication::class => 'doc-1',
        SubmitToInsurer::class   => 'APP-1',
    ])
        ->send(new FormSubmitted(['damage' => 'glass']))
        ->send('client.signed')
        ->assertIn('awaitingParty')
        ->publish(new DocumentsRequested(['photo']), correlate: 'APP-1')
        ->travel('10 days')
        ->assertIn('awaitingParty')
        ->assertRan(ReportMissingDocuments::class)
        ->publish(new PartyDecision(approved: true), correlate: 'APP-1')
        ->assertIn('offering', 'choosingSlot');
});
```

## Driving

| Method | |
|---|---|
| `ProcessTest::start($process, $context, fakes: [...])` | start and run until idle |
| `new ProcessTest(store:, clock:, container:)` then `->begin(...)` | with a real store (a database), your container |
| `send($event, $payload, key:)`, `publish($event, correlate:, key:)` | deliver and run until idle |
| `travel('3 days', step: '1 hour')` | move the clock step by step, firing timers and retries in order |
| `tick()`, `drain()` | one tick; run what is due |
| `crashAt($point, skip: 0)` | make the runtime "die" at a point (below) |
| `recover('10 minutes')` | disarm faults, let leases expire, let the tick pick up the pieces |
| `resolveEffect($class, succeeded:, result:)` | an operator settles an effect |
| `view()`, `engine`, `clock`, `signals` | look inside |

## Fakes

```php
->fake(ChargeCard::class, returns: 'tx-1')
->fake(ChargeCard::class, throws: new RuntimeException('timeout'))             // ambiguous
->fake(ChargeCard::class, sequence: [new EffectNotExecuted('refused'), 'tx-2']) // answers per call
->fake(ChargeCard::class, using: fn (Effect $fx) => 'tx-'.$fx->index)
->fake(ChargeCard::class, returns: 'tx-1', reconcile: Outcome::succeeded('tx-1'))
->fake(ChargeCard::class, returns: 'tx-1', crash: 'after-call')  // dies after the call, before the record
->fake(BookService::class, compensateThrows: new RuntimeException('down'))
```

The fake records calls (`fakeOf($class)->calls`, `->reconciles`,
`->compensations`). The semantics still come from the real class's attribute.

## Assertions

`assertIn(...$states)`, `assertNotIn()`, `assertFinal($state)`,
`assertStatus($status)`, `assertContext('path.to.value', $expected)`,
`assertCalls($class, $n)` (calls of a fake), `assertRan($class, $n)`
(succeeded effects), `assertEffect($class, $status)`,
`assertIncident($type)`, `assertNoIncidents()`.

## Crashes

Fault points where the runtime can be made to die:

| Point | Situation |
|---|---|
| `runner.before_commit` | the step is computed, nothing stored |
| `runner.after_commit` | the step stored, the doorbell not rung |
| `effect.after_claim` | the effect marked running, the handler not called |
| `effect.after_call` | the handler returned, the result not stored |
| `effect.after_record` | the result stored |
| `reconcile.after_call` | a reconciliation returned, not stored |
| `ticker.after_fire` | a timer marked fired, its event not queued |

A crash rolls back the open transaction and leaves leases as a killed worker
would. After `recover()`, check the invariants that matter to you:

```php
it('never charges twice when the worker dies mid-call', function () {
    $t = (new ProcessTest)
        ->fake(ChargeCard::class, returns: 'tx-1', crash: 'after-call', reconcile: Outcome::succeeded('tx-1'))
        ->begin(OrderFlow::class)
        ->send('submit', ['items' => ['sku-1']]);

    $t->recover()
        ->assertCalls(ChargeCard::class, 1)
        ->assertContext('paymentId', 'tx-1')
        ->assertIn('shipping');
});
```

The engine's own suite runs every fault point at several hits through two
scenarios and checks: no pending events left, at most one call per
at-most-once key, unique effect keys, one step per external event, and that
folding the journal's context patches gives the stored context.

## Races

Two engines on one store with different worker ids model two workers:

```php
$store = new InMemoryStore;
$clock = new VirtualClock;
$a = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'A');
$b = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'B');
```

The Laravel bridge's suite adds real processes (`pcntl_fork`) against
SQLite, MySQL and PostgreSQL, and a worker killed with SIGKILL mid-call.
