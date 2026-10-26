# process-engine

[![tests](https://github.com/dskripchenko/process-engine/actions/workflows/tests.yml/badge.svg)](https://github.com/dskripchenko/process-engine/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A framework-agnostic PHP engine for long-running processes: hierarchical state
machines (statecharts) with context, durable across days and weeks, waiting on
external events and timers, with **explicit retry semantics for every side
effect**, versioned definitions, a journal per instance and operator actions.

- **Statecharts with an explicit graph.** Nested and parallel states, history,
  guards, entry/exit/transition actions, choices, child processes. The graph
  is data: stored per version, drawn by [@dskripchenko/flow](https://github.com/dskripchenko/flow),
  checked before it runs.
- **Durable by construction.** A step is a pure function committed in one
  transaction (snapshot, journal, outbox). Nothing lives in memory between
  steps. A crashed worker loses nothing; a duplicate event changes nothing.
- **Side effects you can reason about.** Every effect declares
  `#[Idempotent]` (retried) or `#[AtMostOnce]` (never retried blindly: an
  ambiguous failure becomes `unknown`, then reconciliation, then an operator).
  Compensations, retry policies, incidents.
- **Waiting is first-class.** Events by instance, by correlation key (early
  ones are buffered) or by signed task token; timers and deadlines; polling of
  parties that cannot call back.
- **Versions and operations.** Old instances finish on their version; explicit
  migrations with a dry run. Journal with guard traces, "why is it here",
  move-to-state, retry, skip, resolve, suspend, cancel — all with actor and reason.
- **No dependencies.** Only PHP 8.2+. A Laravel bridge lives in
  [dskripchenko/laravel-process-engine](https://github.com/dskripchenko/laravel-process-engine).

## Install

```bash
composer require dskripchenko/process-engine
```

## A process in a few lines

```php
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

final class DocumentApproval extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('pending', fn (Builder $s) => $s
            ->entry(NotifyApprover::class)
            ->after('5 days', to: 'escalated')
            ->on('approved', to: 'done')
            ->on('rejected', to: 'declined', run: $this->keepReason(...)));

        $p->state('escalated', fn (Builder $s) => $s
            ->on('approved', to: 'done')
            ->on('rejected', to: 'declined', run: $this->keepReason(...)));

        $p->final('done');
        $p->final('declined');
    }

    private function keepReason(array &$context, array $event): void
    {
        $context['reason'] = $event['reason'] ?? null;
    }
}

#[Idempotent]
final class NotifyApprover
{
    public function __invoke(array $context): void
    {
        // send an e-mail; retried on failure
    }
}
```

```php
use Dskripchenko\ProcessEngine\Engine;

$engine = Engine::inMemory();            // or a database store, see the docs
$process = $engine->start(DocumentApproval::class, ['documentId' => 42]);
$engine->send($process->id(), 'approved', key: 'msg-1');
$engine->drain();                        // synchronous mode; workers in production

$engine->get($process->id())->final();   // 'done'
```

## Documentation

| | English | Deutsch | Русский | 中文 |
|---|---|---|---|---|
| Getting started | [en](docs/en/getting-started.md) | [de](docs/de/getting-started.md) | [ru](docs/ru/getting-started.md) | [zh](docs/zh/getting-started.md) |
| Concepts and guarantees | [en](docs/en/concepts.md) | [de](docs/de/concepts.md) | [ru](docs/ru/concepts.md) | [zh](docs/zh/concepts.md) |
| Defining processes | [en](docs/en/builder.md) | [de](docs/de/builder.md) | [ru](docs/ru/builder.md) | [zh](docs/zh/builder.md) |
| Structure: nesting, parallel, children | [en](docs/en/structure.md) | [de](docs/de/structure.md) | [ru](docs/ru/structure.md) | [zh](docs/zh/structure.md) |
| Effects and retry semantics | [en](docs/en/effects.md) | [de](docs/de/effects.md) | [ru](docs/ru/effects.md) | [zh](docs/zh/effects.md) |
| Events, timers, polling | [en](docs/en/events-and-timers.md) | [de](docs/de/events-and-timers.md) | [ru](docs/ru/events-and-timers.md) | [zh](docs/zh/events-and-timers.md) |
| Versions and migrations | [en](docs/en/versioning.md) | [de](docs/de/versioning.md) | [ru](docs/ru/versioning.md) | [zh](docs/zh/versioning.md) |
| Operating instances | [en](docs/en/operations.md) | [de](docs/de/operations.md) | [ru](docs/ru/operations.md) | [zh](docs/zh/operations.md) |
| Drawing with @dskripchenko/flow | [en](docs/en/flow.md) | [de](docs/de/flow.md) | [ru](docs/ru/flow.md) | [zh](docs/zh/flow.md) |
| Testing processes | [en](docs/en/testing.md) | [de](docs/de/testing.md) | [ru](docs/ru/testing.md) | [zh](docs/zh/testing.md) |
| Storage adapters | [en](docs/en/storage.md) | [de](docs/de/storage.md) | [ru](docs/ru/storage.md) | [zh](docs/zh/storage.md) |

## Requirements

PHP 8.2 – 8.5. No runtime dependencies.

## License

MIT. See [LICENSE](LICENSE).
