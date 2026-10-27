# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [1.2.0] - 2026-10-27

### Added
- **Batches** (ADR-014): `Builder::batch()` works a state through many elements
  taken page by page from an `ItemSource`, a chunk per call of an `#[Idempotent]`
  `BatchHandler`, `concurrency` chunks at a time. Each element ends `done`,
  `not_found`, `skipped`, `deferred` or `failed` (tried again up to
  `itemAttempts`); `EffectNotExecuted` makes the stage wait, `EffectFailed`
  stops the batch with `error.invoke`; `done.invoke` carries the counters. A
  separate `BatchStore` contract (implemented by `InMemoryStore`); operations
  `retryBatchItems()`, `skipBatch()`, reads `batches()`, `batch()`,
  `batchItems()`; progress on the diagram node; batches go into the archive.
  About 10 000 elements a second of the engine's own cost, flat to a million.
- **Switchable states** (ADR-015): `Builder::switchable($switch, skipTo:, offOn:)`
  skips a stage switched off everywhere (`Engine::switchOff/switchOn/switchState`),
  for one run (`disabled` in the start context) or by the engine on an error code
  from `offOn`; the skip is noted in the context's `skipped`. `SwitchProvider`
  (implemented by `StaticFlags`, the engine's default flags now); the signal
  `switch.changed`; `FlowExporter` marks switchable nodes `enabled`.
- Fault points `batch.after_call` and `batch.after_record` for the crash harness.

## [1.1.0] - 2026-10-27
### Fixed
- `Process::projections()` is annotated as returning `Projection` objects
  (what `project()` builds and the documentation shows) as well as arrays.
  Hosts that returned `[project(...)]` failed static analysis at level max.

### Added

- `Engine::define(Definition|array)`: store a definition built outside a process class
  (loaded from JSON, made in a visual editor) after validating it; start it by name.

## [1.0.0] - 2026-10-26

### Added
- Process definitions as PHP classes (`Process`, `Builder`) compiled into a
  canonical, hashed JSON form; definitions can also be loaded from JSON.
- Statechart interpreter following the SCXML algorithm: compound and parallel
  states (`join: all|any`), final states with output, choices, shallow and deep
  history, eventless transitions, internal events, a microstep limit.
- Builder sugar: `steps()`, `match:` routing tables, routing by a child's final
  state, `from: '*'`, enum cases as state ids, `meta(label:)` and transition labels,
  declarative predicates and actions (`eq()`, `all()`, `flag()`, `assign()`, …).
- Durable runtime: one transaction per step (snapshot fenced by sequence,
  journal, outbox), inbox with idempotency keys, instance leases, a tick for
  timers, recovery and re-dispatch, synchronous mode (`drain()`).
- Effects with mandatory retry semantics: `#[Idempotent]` and `#[AtMostOnce]`
  (ambiguous failures become `unknown`), `EffectNotExecuted`, `EffectFailed`,
  reconciliation (`Reconcilable`), compensation in reverse order (`Compensable`),
  retry policies as data, `forEach` with concurrency, incidents.
- Events by instance, by correlation key with a buffer for early events, and by
  signed task token; deferred events; sender-side validation (`SelfValidating`);
  timers (`after`, `at`, business days); polls that write no journal while
  nothing changes; `Publish`/`Send` from effects.
- Child processes (`invoke`), multi-instance children (`forEach`), children per
  event (`spawn`) with scopes.
- Versioning: immutable stored versions, execution of the stored form,
  migrations with a dry run and quiescence checks, `adopt()`, `doctor()`.
- Read model (`InstanceView`): labels, accepted events with guards evaluated,
  timers, effects, incidents, journal, "why"; operator actions through the inbox
  with actor and reason; health numbers; signals; archive of finished instances.
- Export to @dskripchenko/flow (Graph v1 and MonitorSnapshot, drill-down) and
  to Mermaid.
- `ProcessTest` harness: virtual clock, fake effects, fault injection at named
  points, assertions.

[1.0.0]: https://github.com/dskripchenko/process-engine/releases/tag/v1.0.0
