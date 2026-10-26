# Concepts and guarantees

## The model: statecharts with an explicit graph

A process definition is a **statechart**: states that nest (compound),
states that run side by side (parallel regions), final states, choices and
history. Transitions react to events, may have a guard and actions, and move
between states. The semantics of a step follow the W3C SCXML algorithm:
transitions are selected from the deepest active states up, conflicting ones
are resolved, states are exited in reverse document order and entered in
document order, eventless transitions and internal events run until nothing
changes.

The graph is **data**. A `Process` class builds a canonical JSON form; that
form is hashed, stored per version and executed. Because the graph is
explicit, the engine can draw it, tell which events an instance accepts now,
move an instance to any state on an operator's request, and keep old
instances on their version.

## A step is a pure function

```
step(definition, snapshot, event, time) → (snapshot', commands, journal data)
```

The interpreter has no storage, no clock and no side effects. Effects,
timers, polls, subscriptions and child processes come out as **commands**.
The runtime then writes, **in one database transaction**: the new snapshot
(checked against its version), the journal entry, the commands (outbox rows,
timers, subscriptions, children) and the "processed" mark of the event.
Either all of it is stored or none.

Guards and actions in the process class get no services and do no I/O. They
see the context, the event and the time of the step. Everything that talks to
the world is an effect, executed **after** the step committed.

## Everything comes in through the inbox

External events, timer firings, effect results, child completions and
operator actions are rows in the instance's inbox, each with an
**idempotency key**. A repeated key is reported as a duplicate and does
nothing. Events of one instance are processed one at a time in arrival order.

## Leases and fencing

A worker takes a short **lease** on an instance before stepping it and
works off its inbox. Each step is committed only if the snapshot's sequence
number is still the one it read; a worker whose lease expired (a long pause,
a network split) finds the instance moved on and rolls back. No database
locks are held during effects, and no database-specific locking is needed.

## The queue is only a doorbell

When a step creates work, the runtime "rings" a dispatcher (a queue job in
Laravel) after the commit. A lost ring loses nothing: the periodic **tick**
fires due timers, recovers effects whose worker died, and re-rings instances
with pending events. Long waits (days, weeks) are timer rows, not delayed
queue jobs.

## Guarantees

| What | Guarantee |
|---|---|
| Transitions | exactly once per inbox event (deduplication + atomic commit + fencing) |
| `#[Idempotent]` effects | at least once; retried on doubt |
| `#[AtMostOnce]` effects | at most once per key; on doubt `unknown`, never retried automatically |
| Order | events of one instance in arrival order; no order across instances |
| Time | timers fire after their moment, with the tick's precision (a minute with the scheduler, seconds with a worker loop) |

## Storage: snapshot plus journal

The **snapshot** (active states, context, activation counters, history) is
the source of truth for execution. The **journal** is an append-only history
written in the same transaction: every step with the event, the transitions
taken, the guards that rejected candidates, the context patch (RFC 6902) and
the actor. The journal is never replayed to rebuild state, but folding its
context patches gives the stored context back, which the test suite checks.

Finished instances move into an **archive**: one compressed row per instance
with its journal, effects and incidents, still readable through the same API.

## Activations

Each entry into a state is a new **activation** (`review@3`). Keys of
effects, timers and subscriptions carry it, so a loop that enters the same
state again starts fresh, and a late result of an earlier activation is
discarded instead of moving the instance.

## Incidents

When something needs a person — an effect out of retries, an unknown outcome
that reconciliation could not settle, a compensation that keeps failing, a
step that threw — the engine opens an **incident** and the rest of the
process keeps working where it can. Incidents are closed by operator actions,
which are journalled with actor and reason.
