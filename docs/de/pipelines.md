# Datenpipelines: Batches und abschaltbare Stufen

Geschäftsprozesse bewegen sich im menschlichen Tempo: Eine Freigabe wartet
tagelang auf eine Unterschrift. Datenpipelines sind anders: Eine Stufe
arbeitet **Tausende oder eine Million Elemente** ab (Objekte zum Anreichern,
Regionen zum Laden, Proxys zum Prüfen), jedes Element endet für sich, ein
externer Dienst begrenzt die Rate, und eine Stufe wird abgeschaltet, wenn ihr
Dienst ausgefallen ist oder ihr Schlüssel fehlt. Zwei Konstrukte decken das
ab: **Batches** und **abschaltbare Zustände**.

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

Die Schlüssel der Elemente kommen **seitenweise aus einer Quelle**, nie über
den Kontext, sodass ein Batch mit einer Million Elementen die Instanz nicht
wachsen lässt:

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

Ein **Handler** erhält einen Chunk von Schlüsseln und meldet das Ergebnis
jedes einzelnen:

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

| Parameter | Standard | |
|---|---|---|
| `chunk` | 100 | Schlüssel pro Aufruf des Handlers |
| `concurrency` | 1 | Chunks gleichzeitig |
| `itemAttempts` | 3 | Versuche eines Elements, bevor es `failed` bleibt |
| `retry:` | die Richtlinie des Handlers | wie ein Chunk wiederholt wird |
| `into:` | — | wohin die Zähler im Kontext geschrieben werden |

Der Handler muss `#[Idempotent]` sein: Ein wiederholter Chunk wiederholt
seine Elemente.

### Ergebnisse

| Der Handler… | Dann |
|---|---|
| gibt Ergebnisse zurück | `done`, `not_found`, `skipped`, `deferred` sind endgültig; `failed` wird in einem späteren Chunk erneut versucht, bis `itemAttempts` erreicht ist, und bleibt dann `failed`. Ein Schlüssel, der im Ergebnis fehlt, zählt als fehlgeschlagen |
| wirft `EffectNotExecuted` (ein Kontingent, eine Pause, eine abgelehnte Verbindung) | der Chunk wird gemäß seiner Richtlinie mit wachsender Pause wiederholt: Die Stufe **wartet**, sie schlägt nicht fehl |
| wirft etwas anderes | dasselbe: Der Handler ist idempotent |
| hat die Chunk-Wiederholungen aufgebraucht | jedes Element des Chunks verliert einen Versuch; der Batch läuft weiter |
| wirft `EffectFailed($code)` (kein Schlüssel, kein Guthaben, gesperrt) | **der Batch stoppt**: Was nicht gelaufen ist, wird `deferred`, der Prozess erhält `error.invoke` mit dem Code — leiten Sie es mit `onError:` weiter |

Wenn die Quelle bis zum Ende gelesen ist und jedes Element ein Ergebnis hat,
erhält der Prozess `done.invoke` mit den Zählern: `{done, not_found, failed, deferred, skipped, total}`.

### Ausführung

Elemente werden im Store gehalten (`BatchStore`), Chunks sind gewöhnliche
Effektzeilen: Leases, Versuchs-Timeouts und die Wiederherstellung durch den
Tick funktionieren wie bei jedem Effekt. Ein Worker, der irgendwo stirbt,
verliert nichts: Jedes Element endet mit einem Ergebnis, und `done` wird
genau einmal zugestellt. Eine pausierte Instanz startet keine neuen Chunks;
das Verlassen des Zustands bricht den Batch ab und stellt zurück, was
gewartet hat.

Der Eigenaufwand der Engine auf PostgreSQL liegt bei etwa 10 000 Elementen
pro Sekunde, konstant vom ersten bis zum millionsten Element (Chunk 500,
Parallelität 8, ein Handler, der nur antwortet).

### Operationen

```php
$engine->batches($instanceId);                       // BatchRecord: status, counters, total
$engine->batchItems($batchId, 'failed', limit: 100);  // elements with their reason and attempts
$engine->retryBatchItems($instanceId, $batchId, $by, 'the timeout is fixed', ['failed', 'deferred']);
$engine->skipBatch($instanceId, $batchId, $by, 'not needed today');
```

Beide Operationen wirken auf einen laufenden Batch und landen im Journal. Ein
beendeter Batch wird durch einen neuen Lauf des Prozesses über seine
Elemente wiederholt oder dadurch, dass die Instanz zurück in den Zustand
versetzt wird (ein neuer Batch von vorn).

Das Diagramm zeigt einen laufenden Batch an seinem Knoten: ein Badge
`finished / total` und eine Zeile mit Zählern.

## Abschaltbare Zustände

```php
$p->state('dadata', fn (Builder $s) => $s
    ->switchable('enrichment.dadata', skipTo: 'nspd', offOn: ['fatal'], label: 'DaData')
    ->batch(...));
```

Ein abschaltbarer Zustand wird nach `skipTo` **übersprungen**, wenn sein
Schalter aus ist. Er ruft nichts auf, und das Überspringen wird in der Liste
`skipped` des Kontexts vermerkt (`{state, switch, reason}`), sodass eine
übersprungene Stufe nie wie eine durchlaufene aussieht. Ein Schalter ist aus:

- **überall** — `$engine->switchOff('enrichment.dadata', $by, 'no key yet')`,
  bis `switchOn()`; Instanzen, die in einem solchen Zustand stehen, verlassen
  ihn sofort (ihr Batch wird abgebrochen);
- **für einen Lauf** — der Schalter ist im Startkontext aufgeführt: `['disabled' => ['enrichment.dadata']]`;
- **durch die Engine** — ein Aufruf des Zustands ist mit einem Code aus
  `offOn` fehlgeschlagen: Der Schalter geht aus, mit der Fehlermeldung als
  Begründung und dem System als Akteur, und das Signal `switch.changed` lässt
  die Anwendung Menschen benachrichtigen.

Ein Schalter, den niemand angefasst hat, ist **an** (anders als ein Flag).
Schalter leben in einem `SwitchProvider`: im Speicher `StaticFlags` (der
Standard der Engine); die Laravel-Bridge hält sie in einer Tabelle.
`$engine->switchState($name)` sagt, wer ihn umgeschaltet hat, wann und warum.
Eine Stufe, die immer laufen muss, ist einfach nicht abschaltbar.

Ein `FlowExporter`, der mit dem Switch-Provider erzeugt wurde, markiert
abschaltbare Knoten mit `enabled` und `data.switch`, sodass das Diagramm
zeigt, welche Stufen aus sind.
