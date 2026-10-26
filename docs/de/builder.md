# Prozesse definieren

## Die Prozessklasse

```php
use Dskripchenko\ProcessEngine\Attributes\ProcessName;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

#[ProcessName('claim-settlement')]        // optional; default: kebab-case class name
final class ClaimSettlement extends Process
{
    public const VERSION = 1;               // required; bump it whenever the graph changes
    public const STATES = ClaimState::class; // optional: an enum of state ids, checked against the graph

    public function define(Builder $p): void { /* the graph */ }

    public function projections(): array { return []; } // optional, see the Laravel bridge
}
```

Die Klasse wird **ohne Konstruktorargumente** instanziiert. Guards und Aktionen
können daher weder Services erreichen noch I/O ausführen; alles Externe ist ein
Effekt. Genau das hält einen Schritt zu einer reinen Funktion.

## Regeln, die der Builder anwendet

- Zustands-IDs sind innerhalb des Prozesses eindeutig; Ziele werden über ihre
  ID angegeben.
- Das zuerst deklarierte Kind ist der Anfangszustand (überschreibbar mit
  `initial()`).
- Guards und Aktionen sind **Methoden des Prozesses**, geschrieben als
  `$this->method(...)`, oder deklarative Prädikate und Aktionen. Anonyme
  Closures werden abgelehnt: Sie lassen sich weder speichern noch versionieren.
- Effekte sind Klassen mit `#[Idempotent]` oder `#[AtMostOnce]`; eine Klasse
  ohne Wiederholungssemantik wird abgelehnt.
- Zustands-IDs können Strings oder Cases eines Backed Enums sein
  (`ClaimState::Review`).

`define()` wird bei der ersten Verwendung in ein kanonisches Array kompiliert.
Fehler (unbekannte Ziele, fehlende Semantik, unbekannte Methoden, nicht
geroutete Endzustände von Kindprozessen) lassen den Build fehlschlagen, wobei
jedes Problem aufgelistet wird; Warnungen weisen auf unerreichbare Zustände und
Verzweigungen hin, die hängen bleiben können.

## Zustände

```php
$p->state('review', fn (Builder $s) => $s /* … */);   // atomic, or compound with children
$p->parallel('agreement', fn (Builder $s) => $s
    ->region('money', fn (Builder $r) => $r /* … */)
    ->region('service', fn (Builder $r) => $r /* … */), join: 'all'); // or 'any'
$p->final('closed', output: 'payoutId');              // a context property or a method
$p->choice('route')->when($this->isMoney(...), 'payout')->otherwise('finalizing');
$p->history('resume', deep: true);                    // inside a compound state
```

Siehe [Struktur](structure.md) für Verschachtelung, parallele Regionen, History
und Kindprozesse.

## Übergänge

```php
->on('client.signed', to: 'review')
->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...))
->on(PartyDecision::class, to: 'rejected', run: $this->storeReason(...))   // first matching wins
->on('client.edit', run: $this->touch(...))                                 // no target: internal
->always(to: 'next', when: $this->ready(...))                               // eventless
->after('10 days', to: 'reminded')                                          // timer, see events
->onDone(to: 'agreement')                                                   // the compound reached its final
```

Parameter von `on()`:

| Parameter | Bedeutung |
|---|---|
| `to:` | Zielzustand; ohne ihn ist der Übergang intern (kein Verlassen/Betreten) |
| `when:` | Guard: eine Methode, die `bool` zurückgibt, oder ein Prädikat |
| `run:` | Aktionen: Methoden, Effektklassen, `assign()`/`raise()`/`note()` oder eine Liste |
| `label:` | eine Beschriftung für Oberflächen (`expects()`, Buttons) |
| `from:` | nur am Prozess: Quellzustände oder `'*'` für jeden nicht beendeten Zustand |
| `match:` + `targets:` + `otherwise:` | eine Routing-Tabelle über ein Ereignisfeld |
| `spawn:` + `input:` + `key:` | einen Kindprozess pro Ereignis starten (siehe Struktur) |

### Routing-Tabellen

```php
->on(ClaimStatusChanged::class, match: 'status', targets: [
    'upload_documents' => 'awaitingDocuments',
    'payment'          => 'paying',
    'done'             => 'settled',
], otherwise: 'reviewing')

$p->choice('kind')->match('scenario', ['documents' => 'documents', 'inspection' => 'inspection'], otherwise: 'info');
```

Jeder Wert wird zu einem gewöhnlichen Übergang mit Guard, sodass der Graph
explizit und darstellbar bleibt.

### Aus jedem Zustand

```php
$p->on('client.cancelled', to: 'cancelling', from: ['application', 'review']);
$p->on('client.cancelled', to: 'cancelled', from: '*');
```

## Guards und Aktionen als Methoden

Argumente werden **nach Typ und Name** injiziert:

| Parameter | Erhält |
|---|---|
| die Kontextklasse (`ClaimContext $c`) | das Kontextobjekt (Änderungen werden gespeichert) |
| `array $context` / `array &$context` | das Kontext-Array (per Referenz, um es zu ändern) |
| eine Ereignisklasse (`PartyDecision $e`) | das aus seinem Payload rekonstruierte Ereignis |
| `array $event` | den Payload des Ereignisses |
| `StepContext $step` | den Zeitpunkt des Schritts, die Instanz-ID, `isIn()`, `spawned()` |
| andere | Extras (das Element eines `forEach`, …) |

```php
private function isApproved(PartyDecision $e): bool { return $e->approved; }
private function countRequest(ClaimContext $c): void { $c->documentRequests++; }
private function sla(array $context): string { return $context['vip'] ? '1 hour' : '1 day'; }
```

## Der Kontext

Ein Array oder ein Objekt mit öffentlichen Properties (Constructor Property
Promotion funktioniert). Unterstützte Typen: Skalare, Arrays, Backed Enums,
`DateTimeImmutable`, verschachtelte Objekte; eine Liste von Objekten benötigt
`#[ListOf(Item::class)]`. Halten Sie große Daten (Dateien, Dokumente) außerhalb
und speichern Sie Referenzen. Die Standardgrößengrenze beträgt 256 KB JSON.

Effekte ändern den Kontext nie direkt: Sie geben ein Ergebnis zurück, das mit
`into:` gespeichert oder von einer `onDone`-Methode verwendet wird. So bleibt
jede Kontextänderung innerhalb einer Schritttransaktion.

## Deklarative Prädikate und Aktionen

Für aus JSON geladene Definitionen oder kurze Guards:

```php
use function Dskripchenko\ProcessEngine\{eq, gt, in, exists, all, any, not, flag, assign, fromEvent, inc, raise, note};

->on('pay', to: 'paid', when: all(gt('$.event.amount', 0), not(exists('blocked'))))
->on('edit', run: assign('form', fromEvent('form')))
->on('retry', run: [assign('attempts', inc(1)), note('retried by the client')])
->on('fast', to: 'express', when: flag('express-lane'))   // a host feature switch
```

Pfade ohne `$.` sind Kontext-Properties (`'amount'` ist `$.context.amount`).
Operatoren: `eq ne gt gte lt lte in exists empty all any not flag`.

## Aktivitäten eines Zustands

```php
->entry(NotifyClient::class, $this->stamp(...))   // on entry: effects and methods
->exit(note('left review'))
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing')   // lives while the state is active
->poll(CheckSignature::class, every: '1 minute')                       // see events
->correlate([PartyDecision::class], by: 'externalId')                  // see events
->defer(PartyDecision::class)                                          // keep until a state accepts it
->compensate(onDone: 'cancelled')                                      // see effects
->meta(label: 'Under review', description: 'The insurer checks the claim')
```

### Optionen von `invoke()`

| Option | Bedeutung |
|---|---|
| `into:` | Kontext-Property, die das Ergebnis erhält |
| `input:` | eine Methode, die die Eingabe aufbaut (`Effect::$input` oder den Kontext des Kindprozesses) |
| `forEach:` | eine Kontextliste: ein Aufruf pro Element, Ergebnisse in Reihenfolge gesammelt |
| `concurrency:` | höchstens so viele Aufrufe eines `forEach` gleichzeitig |
| `onDone:` | Ziel; bei einem Kindprozess eine Zuordnung Endzustand → Ziel |
| `onError:` | Ziel oder eine Zuordnung Fehlercode → Ziel (`'*'` für den Rest) |
| `onUnknown:` | wohin es geht, wenn ein at-most-once-Ergebnis unbekannt ist (Standard: warten) |
| `retry:` | eine `Retry`-Richtlinie, die die Standardrichtlinie des Effekts überschreibt |

## Ketten von Effekten

```php
use function Dskripchenko\ProcessEngine\step;

$p->steps('registration', [
    step(AddParticipant::class, forEach: 'participants', into: 'participantIds'),
    step(CreateClaims::class, into: 'claims'),
    step(AttachDocument::class, forEach: 'documents', concurrency: 5),
], onDone: 'settlement', onError: 'registrationFailed');
```

Jeder Schritt wird zu einem eigenen Zustand (sichtbar im Journal und in flow)
und geht zum nächsten über, sobald sein Effekt abgeschlossen ist.

## Beschriftungen für Oberflächen

`meta(label:)` an Zuständen und `label:` an Übergängen werden von der Engine
nicht ausgewertet. `InstanceView::label()` gibt die Beschriftung des innersten
beschrifteten aktiven Zustands zurück; `expects()` gibt die aktuell
akzeptierten Ereignisse mit ihren Beschriftungen zurück — die Buttons, die ein
Kunde drücken kann.
