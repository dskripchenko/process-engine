# process-engine

A framework-agnostic PHP engine for long-running processes: hierarchical
state machines (statecharts) with context, durable execution across days and
weeks, waiting on external events and timers, explicit retry semantics for
every side effect (idempotent, at-most-once with an "unknown" outcome,
compensable), versioned definitions, a per-instance journal and manual
operations. A Laravel layer and an export to `@dskripchenko/flow` are planned.

**Status:** design stage. No code yet; the architecture decisions are being
reviewed. Not published.

## License

MIT. See [LICENSE](LICENSE).
