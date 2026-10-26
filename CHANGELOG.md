# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [Unreleased]

### Fixed
- `Process::projections()` is annotated as returning `Projection` objects
  (what `project()` builds and the documentation shows) as well as arrays.
  Hosts that returned `[project(...)]` failed static analysis at level max.

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
