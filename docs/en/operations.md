# Operating instances

## Looking at an instance

```php
$view = $engine->get($instanceId);     // or find() for null when missing

$view->status();          // running | suspended | completed | failed | cancelled
$view->active();          // every active state, outermost first
$view->leaves();          // where it stands
$view->is('review');      // also true for the final state of a finished instance
$view->label();           // client-facing status: meta.label of the innermost labelled state
$view->expects();         // events accepted now (guards on the context evaluated), timers
$view->context();         // the context object (or array); contextArray() for the array
$view->final(); $view->output();
$view->timers();          // pending, with fireAt; overdueTimers() for late ones
$view->subscriptions();   // correlation keys it listens on
$view->pendingEffects();  // running, waiting, unknown
$view->incidents();       // open ones
$view->children();
$view->journal();         // the history
$view->why();             // the journal entry that brought it here
$view->toArray();
```

`expects()` evaluates guards that depend only on the context; events whose
guard reads the event are marked `conditional`. Use it to show the actions a
client can take now.

Lists and counters:

```php
use Dskripchenko\ProcessEngine\Store\InstanceQuery;

$engine->instances(new InstanceQuery(state: 'awaitingDocuments', definitionName: 'claim-settlement'));
$engine->instances(new InstanceQuery(hasIncidents: true));
$engine->stateCounts(ClaimSettlement::class);   // state => instances, for an overview map
$engine->openIncidents();
$engine->health();   // running, suspended, with_incidents, open_incidents, overdue_timers, expired_effect_leases
```

## The journal

Every entry has a type, data, the actor, the reason and a time. The main types:

| Type | Data |
|---|---|
| `started`, `adopted`, `migrated` | version, context, states |
| `step` | the event and its payload, transitions taken (with the guard that allowed each), candidates rejected by their guards, states exited and entered, the context patch (RFC 6902), notes, commands |
| `event.ignored`, `event.deferred`, `event.discarded` | why, and what was expected |
| `effect.succeeded`, `effect.retry`, `effect.failed`, `effect.unknown`, `effect.reconciled`, `effect.lease_expired`, `effect.compensated`, `effect.orphaned` | key, class, attempt, result or error |
| `timer.fired`, `poll.result`, `child.started` | |
| `incident.opened` | type, message |
| `manual.*`, `manual.rejected` | the operation, parameters, actor, reason |
| `completed`, `failed`, `cancelled` | final state, output |

Payloads are stored as sent: "who entered what" is in the journal.

## Operator actions

Every action needs an actor and a **reason**. Actions go through the inbox,
so they are serialized with ordinary events and land in the journal.

```php
$by = Actor::operator($user->id, $user->name);

$engine->transitionTo($id, ['awaitingParty'], $by, 'the insurer confirmed by phone');
$engine->transitionTo($id, ['review'], $by, 'reset', runExit: false, runEntry: false);
$engine->patchContext($id, [['op' => 'replace', 'path' => '/externalId', 'value' => 'APP-7']], $by, 'typo');
$engine->suspend($id, $by, 'fraud investigation');    // events wait, effects do not start
$engine->resume($id, $by, 'cleared');
$engine->cancel($id, $by, 'client withdrew');
$engine->cancel($id, $by, 'client withdrew', compensate: true);

$engine->retryEffect($effectId, $by, 'the provider is back');
$engine->retryEffect($effectId, $by, 'the bank confirmed nothing was charged', confirmUnsafe: true);
$engine->resolveEffect($effectId, $by, 'found the transfer in the bank statement', succeeded: true, result: 'TR-55');
$engine->resolveEffect($effectId, $by, 'the transfer was rejected', succeeded: false, code: 'rejected');
$engine->skipEffect($effectId, $by, 'sent by hand', result: null);
$engine->resolveIncident($incidentId, $by, 'handled');
$engine->redeliver($id, $inboxId, $by, 'retry after the fix');   // re-run an event whose step threw
```

- `transitionTo` ignores guards. Exit and entry actions run unless switched off.
- `resolveEffect` / `skipEffect` deliver the usual `done` / `error` event, so
  the process continues as if the call had returned.
- `retryEffect` on an unknown at-most-once effect is refused without
  `confirmUnsafe: true`.
- A suspended instance still accepts operator actions; its events wait.

Authorization is the host's business: check permissions before calling these.

## Incidents

| Type | When |
|---|---|
| `effect_exhausted` | an effect ran out of attempts with `onExhausted: 'incident'` |
| `effect_failed` | an error nobody handled (no `onError`), a fire-and-forget effect failed |
| `effect_unknown` | an at-most-once outcome could not be settled |
| `compensation_failed`, `compensation_blocked` | undoing failed / waits for an unknown outcome |
| `step_error` | a guard or an action threw: the event is marked failed, `redeliver()` after the fix |
| `microstep_limit` | eventless transitions loop |
| `handler_missing` | an effect class referred to by a live version is gone |
| `context_too_large` | the context exceeds the limit |
| `poll_failing` | a poll keeps failing |

An instance with open incidents is flagged (`hasIncidents`) and stays out of
the archive; the rest of it keeps working where it can.

## Signals

Pass an `EventListener` to the engine to receive signals after commit:
`instance.started`, `step.committed`, `event.ignored`, `effect.succeeded`,
`effect.failed`, `effect.unknown`, `incident.opened`, `incident.resolved`,
`instance.completed`, `instance.failed`, `instance.cancelled`. The Laravel
bridge turns them into `ProcessSignal` events.

## Driving the runtime

| Call | Who calls it |
|---|---|
| `runInstance($id)` | a worker when an instance has events (a queue job in Laravel) |
| `executeEffect($id)` | a worker when an effect is due |
| `tick()` | every minute: fires timers, recovers effects of dead workers, re-rings lost work, purges expired messages |
| `archive()` | daily: moves finished instances older than 30 days into the archive |
| `drain()` | synchronous mode (tests, scripts): runs everything rung until idle |

## Archive

Finished instances without open incidents or live children move into the
archive after `archiveAfter` (30 days by default): one row per instance with
the snapshot, journal, effects and incidents compressed. `get()` still returns
them (`isArchived()`), read-only.
