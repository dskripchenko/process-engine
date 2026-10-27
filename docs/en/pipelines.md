# Data pipelines: batches and switchable stages

Business processes move at a human pace: an approval waits days for a signature.
Data pipelines are different: a stage works through **thousands or a million
elements** (objects to enrich, regions to load, proxies to check), each element
ends on its own, an external service limits the rate, and a stage is switched
off when its service is down or its key is missing. Two constructs cover this:
**batches** and **switchable states**.

## Batches

```php
use Dskripchenko\ProcessEngine\Batch\BatchHandler;
use Dskripchenko\ProcessEngine\Batch\BatchResult;
use Dskripchenko\ProcessEngine\Batch\ItemOutcome;
use Dskripchenko\ProcessEngine\Batch\ItemSource;

$p->state('dadata', fn (Builder $s) => $s
    ->batch(DadataLookup::class, items: ObjectsToEnrich::class, chunk: 50, concurrency: 2,
        itemAttempts: 3, into: 'counters.dadata', onDone: 'nspd', onError: ['fatal' => 'nspd']));
```

The keys of the elements come **page by page from a source**, never through the
context, so a batch of a million elements does not grow the instance:

```php
final class ObjectsToEnrich implements ItemSource
{
    public function __construct(private readonly Objects $objects) {}

    /** The next page strictly after $after, in a stable order; [] — no more. */
    public function keys(array $context, ?string $after, int $limit): array
    {
        return $this->objects->idsOfGroup($context['group'], after: (int) $after, limit: $limit);
    }

    /** Optional: the total, for progress. */
    public function count(array $context): ?int
    {
        return $this->objects->countOfGroup($context['group']);
    }
}
```

A **handler** gets a chunk of keys and tells the outcome of each:

```php
#[Idempotent(retry: new Retry(attempts: 10, initialInterval: '1 minute', maxInterval: '1 hour'))]
final class DadataLookup implements BatchHandler
{
    public function handle(array $context, array $keys, Effect $fx): BatchResult
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = match ($this->lookup($key)) {
                'found' => ItemOutcome::done(),
                'none' => ItemOutcome::notFound(),
                default => ItemOutcome::failed('timeout'),
            };
        }

        return BatchResult::of($out);
    }
}
```

| Parameter | Default | |
|---|---|---|
| `chunk` | 100 | keys per call of the handler |
| `concurrency` | 1 | chunks at a time |
| `itemAttempts` | 3 | tries of one element before it stays `failed` |
| `retry:` | the handler's policy | how a chunk is retried |
| `into:` | — | where the counters go in the context |

The handler must be `#[Idempotent]`: a retried chunk repeats its elements.

### Outcomes

| The handler… | Then |
|---|---|
| returns outcomes | `done`, `not_found`, `skipped`, `deferred` are final; `failed` is tried again in a later chunk until `itemAttempts`, then stays `failed`. A key missing from the result counts as failed |
| throws `EffectNotExecuted` (a quota, a pause, a connection refused) | the chunk is retried under its policy with a growing pause: the stage **waits**, it does not fail |
| throws anything else | the same: the handler is idempotent |
| ran out of chunk retries | each element of the chunk loses one attempt; the batch goes on |
| throws `EffectFailed($code)` (no key, no balance, banned) | **the batch stops**: what has not run is `deferred`, the process gets `error.invoke` with the code — route it with `onError:` |

When the source is read to the end and every element has an outcome, the process
gets `done.invoke` with the counters: `{done, not_found, failed, deferred, skipped, total}`.

### Running it

Elements are kept in the store (`BatchStore`), chunks are ordinary effect rows:
leases, attempt timeouts and the ticker's recovery work as for any effect. A
worker that dies anywhere loses nothing: every element ends with an outcome and
`done` is delivered once. A suspended instance starts no new chunks; leaving the
state cancels the batch and defers what waited.

The engine's own cost on PostgreSQL is about 10 000 elements a second, flat from
the first element to the millionth (chunk 500, concurrency 8, a handler that
only answers).

### Operations

```php
$engine->batches($instanceId);                       // BatchRecord: status, counters, total
$engine->batchItems($batchId, 'failed', limit: 100);  // elements with their reason and attempts
$engine->retryBatchItems($instanceId, $batchId, $by, 'the timeout is fixed', ['failed', 'deferred']);
$engine->skipBatch($instanceId, $batchId, $by, 'not needed today');
```

Both operations work on a running batch and go into the journal. A finished batch
is retried by a new run of the process over its elements, or by moving the
instance back into the state (a new batch from scratch).

The diagram shows a running batch on its node: a badge `finished / total` and a
line of counters.

## Switchable states

```php
$p->state('dadata', fn (Builder $s) => $s
    ->switchable('enrichment.dadata', skipTo: 'nspd', offOn: ['fatal'], label: 'DaData')
    ->batch(...));
```

A switchable state is **skipped** to `skipTo` when its switch is off. It invokes
nothing, and the skip is noted in the context's `skipped` list
(`{state, switch, reason}`), so a skipped stage never looks like a passed one.
A switch is off:

- **everywhere** — `$engine->switchOff('enrichment.dadata', $by, 'no key yet')`,
  until `switchOn()`; instances standing in such a state leave it at once (their
  batch is cancelled);
- **for one run** — the switch is listed in the start context: `['disabled' => ['enrichment.dadata']]`;
- **by the engine** — an invocation of the state failed with a code from `offOn`:
  the switch goes off with the error's message as the reason and the system as the
  actor, and the signal `switch.changed` lets the application tell people.

A switch nobody touched is **on** (unlike a flag). Switches live in a
`SwitchProvider`: in memory, `StaticFlags` (the engine's default); the Laravel
bridge keeps them in a table. `$engine->switchState($name)` tells who switched it,
when and why. A stage that must always run simply is not switchable.

`FlowExporter` built with the switch provider marks switchable nodes with
`enabled` and `data.switch`, so the diagram shows which stages are off.
