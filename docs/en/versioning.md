# Versions and migrations

## A version never changes

Every process class declares `VERSION`. When an instance starts, the
canonical form of that version is stored with its hash. Registering the same
version with a different graph throws `DefinitionVersionConflict`: change the
graph, bump the version.

```php
final class ClaimSettlement extends Process
{
    public const VERSION = 2;   // was 1
    // …
}
```

(In development you may allow redefining a version no instance uses yet:
`new Engine(..., allowRedefineUnused: true)`.)

## Old instances finish on their version

An instance is bound to the stored version it started with, and the engine
executes **the stored form**, not the class. You can rewrite `define()` for
version 2; instances of version 1 keep running on the version 1 graph.

New instances start on the version the class declares. `start('claim-settlement')`
by name starts the latest stored version; `start(..., version: 1)` a given one.

What must stay in the code while old versions have live instances: the
**names** they refer to — methods of the process class used as guards and
actions, effect classes, child process classes. Change behaviour
incompatibly under a new name (`ChargeV2`) and keep the old one until its
instances are gone.

`$engine->doctor()` (artisan `process-engine:doctor`) lists stored versions
with live instances whose methods or classes are missing. A missing handler
at run time opens an incident instead of failing the instance.

## Migrating instances

Migration is explicit, per instance, journalled, and checked first.

```php
use Dskripchenko\ProcessEngine\Versioning\Migration;

$migration = Migration::from(ClaimSettlement::class, 1)->to(2)
    ->mapState('waiting', 'awaitingParty')        // renamed states
    ->dropState('legacyFax')                      // instances standing here are not migrated
    ->context(fn (array $c) => [...$c, 'requests' => count($c['docRequests'] ?? [])])
    ->when(fn (InstanceView $i) => $i->businessKey() !== null);

$report = $engine->migrate($migration, Actor::operator('ann'), 'introduce the fraud check');   // dry run
$report->eligible;   // instance ids
$report->skipped;    // id => why not

$engine->migrate($migration, Actor::operator('ann'), 'introduce the fraud check', dryRun: false);
```

An instance is migrated only when it is quiet:

- it runs the source version and is not finished;
- no open incidents;
- no effect running or with an unknown outcome;
- no invoked child process running;
- every active state has a counterpart (mapped or with the same id) in the target.

Applying it, under the instance lease:

1. everything bound to the old activations ends: pending effects, timers,
   polls, subscriptions;
2. the context is transformed;
3. the instance enters the mapped states of the new version **without entry
   actions** — it is a move, not a new entry; their timers, polls,
   subscriptions and invocations start (timers count from the migration);
4. the journal gets a `migrated` entry with the state map, actor and reason.

Migrations are code run by an operator, so closures are fine here. Register
them with `$engine->registerMigration()` to apply them by name later
(`process-engine:migrate claim-settlement@1->2 --apply --reason=…`).

## Bringing processes in from outside

`adopt()` creates an instance directly in given states — for processes that
ran in legacy code, or records created elsewhere:

```php
$engine->adopt(ClaimCase::class, in: ['awaitingDocuments'], context: $context,
    key: "claim:{$legacyId}", by: Actor::operator('migration'), reason: 'move from the old system');
```

Entry actions do not run; timers, polls, subscriptions and invocations of the
states start. The key makes a batch import safe to rerun.
