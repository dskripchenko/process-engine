# Storage adapters

The engine persists through one interface,
`Dskripchenko\ProcessEngine\Store\Store`. The core ships `InMemoryStore`
(tests, scripts); the Laravel bridge ships a database store for PostgreSQL,
MySQL / MariaDB and SQLite. Write your own to run on something else.

## What the contract asks for

- **`transaction(callable)`** — the unit of work of a step: everything
  written inside commits together or not at all; nested calls join the outer
  transaction.
- **Fencing** — `updateInstance($record, $expectedSeq)` writes only when the
  stored `seq` equals `$expectedSeq` and returns `false` otherwise. This is
  what makes a worker with an expired lease harmless.
- **Compare-and-set** — `acquireLease()`, `claimEffect()` and `fireTimer()`
  are atomic conditional updates (`UPDATE … WHERE status = ? …`), committed
  on their own.
- **Idempotent inserts** — `insertInbox()` (unique per instance and key),
  `insertEffect()` and `insertTimer()` (unique key), `insertMessage()`
  (unique event + correlation key + key), `insertInstance()` (unique id, and
  business key within a process) return `false` on a duplicate instead of
  throwing.
- **Order** — `nextInbox()` returns pending events in arrival order;
  `appendJournal()` assigns an increasing `seq`.
- **Queries** — instances by status, process, version, state, business key,
  subject, incidents; due timers and effects; expired leases; archivable
  instances.

No database-specific locking is needed: conditional updates and unique keys
are enough on every SQL database.

## Tables (the database store)

| Table | Holds |
|---|---|
| `definitions` | stored versions: name, version, hash, canonical JSON |
| `instances` | snapshot, seq, lease, parent, business key, subject |
| `active_states` | active states as rows, for indexed "instances in state X" |
| `inbox` | events with their keys and status |
| `effects` | the outbox: key, class, semantics, policy, status, attempt, lease, result |
| `timers` | key, event, moment, status |
| `subscriptions`, `messages` | correlation and the buffer of early events |
| `incidents`, `journal` | |
| `archive` | one compressed document per finished instance |

## Scale

The design targets around 100,000 active instances and 10 million archived
ones: hot tables hold active and recently finished instances only, and an
archived instance is a single row. Journals of active instances are paged by
`(instance_id, seq)`.

## Notes for SQLite

With several workers, configure the connection with
`transaction_mode = IMMEDIATE` and WAL: deferred transactions that upgrade
to writes fail with "database is locked" instead of waiting. For one worker
or tests, the defaults are fine.
