# Effekte und Wiederholungssemantik

Ein **Effekt** ist alles, was die Außenwelt berührt: ein API-Aufruf, eine
Zahlung, eine E-Mail, ein generiertes Dokument. Effekte laufen nie innerhalb
eines Schritts: Der Schritt legt sie in einer Outbox ab, und sie laufen nach dem
Commit des Schritts, außerhalb jeder Transaktion und Sperre. Ihr Ergebnis kommt
als Ereignis zum Prozess zurück.

## Semantik ist Pflicht

```php
use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
```

| Attribut | Garantie | Wenn das Ergebnis zweifelhaft ist |
|---|---|---|
| `#[Idempotent]` | läuft mindestens einmal | wird gemäß seiner Richtlinie wiederholt |
| `#[AtMostOnce]` | läuft höchstens einmal pro Schlüssel | wird `unknown`: keine automatische Wiederholung |

Es gibt keinen Standard. Eine Effektklasse ohne eines dieser Attribute wird
beim Build des Prozesses abgelehnt — der Autor muss entscheiden, ob eine
Wiederholung des Aufrufs sicher ist. Eine E-Mail zweimal zu senden ist
lästig; eine Karte zweimal zu belasten ist mehr als das.

## Einen Effekt schreiben

```php
#[AtMostOnce]
final class SubmitToInsurer implements Reconcilable
{
    public function __construct(private readonly InsurerApi $api) {}  // any services

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        try {
            return $this->api->submit($claim->application, idempotencyKey: $fx->key)->id;
        } catch (ConnectionRefused $e) {
            throw new EffectNotExecuted($e);   // never reached the other side
        }
    }

    public function reconcile(ClaimContext $claim, Effect $fx): Outcome
    {
        $found = $this->api->findByIdempotencyKey($fx->key);

        return $found ? Outcome::succeeded($found->id) : Outcome::stillUnknown();
    }
}
```

Argumente werden nach Typ injiziert: der Kontext (Objekt oder `array $context`),
`Effect $fx` sowie `$item` / `$input` / `$result` nach Name. `Effect` enthält:

| Feld | |
|---|---|
| `key` | der Idempotenzschlüssel: gleich über Wiederholungen eines Aufrufs, neu bei einer neuen Aktivierung des Zustands |
| `instanceId`, `attempt`, `now` | |
| `item`, `index` | das Element eines `forEach` |
| `input` | was `invoke(..., input:)` aufgebaut hat |

**Geben Sie `$fx->key` an die Gegenseite weiter**, wo immer sie
Idempotenzschlüssel unterstützt. Das macht einen Abgleich erst möglich.

## Wie ein Ergebnis eingeordnet wird

| Der Handler… | Ergebnis |
|---|---|
| gibt einen Wert zurück | `succeeded`; der Prozess erhält `done.invoke.<id>` mit `output` |
| wirft `EffectNotExecuted` | der Aufruf hat sicher nicht stattgefunden: wird wiederholt (bei jeder Semantik), solange Versuche übrig sind |
| wirft `EffectFailed($code)` | der Aufruf hat stattgefunden und wurde abgelehnt: `failed`, `error.invoke.<id>` mit dem Code, keine Wiederholung |
| wirft etwas anderes, läuft in ein Timeout oder sein Worker stirbt | mehrdeutig: `#[Idempotent]` wiederholt, `#[AtMostOnce]` wird `unknown` |

Faustregeln für HTTP-Clients: Verbindung abgelehnt oder DNS-Fehler vor dem
Senden → `EffectNotExecuted`; eine fachliche 4xx-Antwort → `EffectFailed`; ein
Lese-Timeout oder ein 5xx nach dem Senden → durchreichen (mehrdeutig).

## Höchstens einmal, genau betrachtet

1. Der Effekt wird beansprucht: `running`, Versuch + 1, eine Lease. Diese
   „gestartet“-Markierung wird **vor** dem Aufruf committet.
2. Der Handler läuft.
3. Das Ergebnis wird zusammen mit dem Ereignis für den Prozess committet.

Stirbt der Worker zwischen 1 und 3, läuft die Lease ab, und der Tick markiert
den Effekt als `unknown` — nie als `pending`. Der Prozess erhält
`unknown.invoke.<id>`.

Danach:
- mit `Reconcilable` läuft `reconcile()` nach eigener Richtlinie (standardmäßig
  10 Versuche, 1 Minute → 6 Stunden). `succeeded`/`failed` schließen den Effekt,
  als wäre der Aufruf zurückgekehrt; `notExecuted` erlaubt einen weiteren
  echten Aufruf; `stillUnknown` versucht es später erneut und eröffnet, wenn die
  Versuche erschöpft sind, einen Incident;
- ohne es wird sofort ein Incident eröffnet.

Der Prozess wartet im Zustand (oder geht zu `onUnknown:`, falls deklariert).
Ein Operator klärt ihn mit `resolveEffect()`, nachdem er die Gegenseite geprüft
hat, oder führt ihn mit `retryEffect(..., confirmUnsafe: true)` erneut aus und
übernimmt damit im Journal die Verantwortung.

## Wiederholungsrichtlinien

```php
use Dskripchenko\ProcessEngine\Effect\Retry;

#[Idempotent(
    retry: new Retry(attempts: 5, initialInterval: '10 seconds', backoff: 2.0, maxInterval: '1 hour',
                     jitter: true, attemptTimeout: '2 minutes', onExhausted: 'error'),
    nonRetryable: [ValidationError::class],
)]
```

| Feld | Standard (idempotent / at most once) |
|---|---|
| `attempts` | 5 / 3 (nur `EffectNotExecuted` wiederholt einen at-most-once-Effekt) |
| `initialInterval`, `backoff`, `maxInterval`, `jitter` | 10 s, ×2, 1 h, an |
| `attemptTimeout` | 2 Minuten: die Lease eines Versuchs; ein längerer Aufruf gilt als tot |
| `onExhausted` | `error`: `error.invoke.<id>` mit dem Code `retries_exhausted` (unbehandelt → Incident); `incident`: ein Incident, der Prozess wartet; `fail`: die Instanz schlägt fehl |

`invoke(..., retry: new Retry(...))` überschreibt die Richtlinie an einer Stelle
im Graphen; die Semantik bleibt die der Klasse.

## Ergebnisse und Fehler im Graphen

```php
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing', onError: [
    'bad_template' => 'manualCheck',
    '*'            => 'failed',
])
```

Ein Effekt kann auch `Publish::to($correlationKey, $event)` oder
`Send::to($instanceId, $event)` (oder eine Liste davon) zurückgeben, um
Ereignisse an andere Instanzen zuzustellen — siehe
[Ereignisse](events-and-timers.md).

Effekte in `entry()`, `exit()` und `run:` sind Fire-and-Forget: Ihr Ergebnis
bewegt den Prozess nicht, aber sie folgen derselben Semantik, und ein Effekt,
der endgültig fehlschlägt, eröffnet einen Incident.

## Kompensation (Saga)

Ein Effekt, der rückgängig gemacht werden kann, implementiert `Compensable`:

```php
#[AtMostOnce]
final class PayOut implements Compensable, Reconcilable
{
    public function __invoke(ClaimContext $claim, Effect $fx): string { /* transfer */ }
    public function reconcile(Effect $fx): Outcome { /* look up by $fx->key */ }
    public function compensate(mixed $result): void { /* refund the transfer $result */ }
}
```

```php
$p->state('cancelling', fn (Builder $s) => $s->compensate(onDone: 'cancelled'));
// or for one stage:
$p->state('undoBooking', fn (Builder $s) => $s->compensate(onDone: 'choosingSlot', scope: 'agreement'));
```

- Beim Betreten werden die abgeschlossenen kompensierbaren Effekte (des
  Prozesses oder des angegebenen Zustands) **in umgekehrter
  Abschlussreihenfolge, einer nach dem anderen** rückgängig gemacht.
- `compensate()` erhält das ursprüngliche Ergebnis; es wird wiederholt
  (standardmäßig 20 Versuche) und eröffnet danach einen Incident.
- Ein Effekt im Zustand `unknown` innerhalb des Gültigkeitsbereichs
  **blockiert** die Kompensation (mit einem Incident): Etwas rückgängig zu
  machen, das vielleicht nie passiert ist, ist falsch.
- `Engine::cancel($id, ..., compensate: true)` kompensiert den gesamten Prozess
  und bricht ihn dann ab.

## Polls

Ein Poll ist ein periodischer idempotenter Effekt, der an einen Zustand
gebunden ist; siehe [Ereignisse](events-and-timers.md#polling).
