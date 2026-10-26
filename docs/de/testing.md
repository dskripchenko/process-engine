# Prozesse testen

`ProcessTest` führt einen Prozess synchron auf einer In-Memory-Engine mit
virtueller Uhr, Fake-Effekten und Fehlerinjektion aus. Es ist das Harness, auf
dem die eigene Testsuite der Engine aufbaut.

```php
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

it('settles a repair claim after a document request', function () {
    ProcessTest::start(ClaimSettlement::class, new ClaimContext(policyId: 'P-1'), fakes: [
        RenderApplication::class => 'doc-1',
        SubmitToInsurer::class   => 'APP-1',
    ])
        ->send(new FormSubmitted(['damage' => 'glass']))
        ->send('client.signed')
        ->assertIn('awaitingParty')
        ->publish(new DocumentsRequested(['photo']), correlate: 'APP-1')
        ->travel('10 days')
        ->assertIn('awaitingParty')
        ->assertRan(ReportMissingDocuments::class)
        ->publish(new PartyDecision(approved: true), correlate: 'APP-1')
        ->assertIn('offering', 'choosingSlot');
});
```

## Steuerung

| Methode | |
|---|---|
| `ProcessTest::start($process, $context, fakes: [...])` | starten und laufen lassen, bis nichts mehr zu tun ist |
| `new ProcessTest(store:, clock:, container:)` dann `->begin(...)` | mit einem echten Store (einer Datenbank) und Ihrem Container |
| `send($event, $payload, key:)`, `publish($event, correlate:, key:)` | zustellen und laufen lassen, bis nichts mehr zu tun ist |
| `travel('3 days', step: '1 hour')` | die Uhr schrittweise vorstellen und dabei Timer und Wiederholungen in Reihenfolge auslösen |
| `tick()`, `drain()` | ein Tick; ausführen, was fällig ist |
| `crashAt($point, skip: 0)` | die Laufzeitumgebung an einem Punkt „sterben“ lassen (siehe unten) |
| `recover('10 minutes')` | Fehler entschärfen, Leases ablaufen lassen, den Tick die Scherben aufsammeln lassen |
| `resolveEffect($class, succeeded:, result:)` | ein Operator klärt einen Effekt |
| `view()`, `engine`, `clock`, `signals` | hineinschauen |

## Fakes

```php
->fake(ChargeCard::class, returns: 'tx-1')
->fake(ChargeCard::class, throws: new RuntimeException('timeout'))             // ambiguous
->fake(ChargeCard::class, sequence: [new EffectNotExecuted('refused'), 'tx-2']) // answers per call
->fake(ChargeCard::class, using: fn (Effect $fx) => 'tx-'.$fx->index)
->fake(ChargeCard::class, returns: 'tx-1', reconcile: Outcome::succeeded('tx-1'))
->fake(ChargeCard::class, returns: 'tx-1', crash: 'after-call')  // dies after the call, before the record
->fake(BookService::class, compensateThrows: new RuntimeException('down'))
```

Der Fake zeichnet Aufrufe auf (`fakeOf($class)->calls`, `->reconciles`,
`->compensations`). Die Semantik kommt weiterhin aus dem Attribut der echten
Klasse.

## Assertions

`assertIn(...$states)`, `assertNotIn()`, `assertFinal($state)`,
`assertStatus($status)`, `assertContext('path.to.value', $expected)`,
`assertCalls($class, $n)` (Aufrufe eines Fakes), `assertRan($class, $n)`
(erfolgreiche Effekte), `assertEffect($class, $status)`,
`assertIncident($type)`, `assertNoIncidents()`.

## Abstürze

Fehlerpunkte, an denen die Laufzeitumgebung zum Absturz gebracht werden kann:

| Punkt | Situation |
|---|---|
| `runner.before_commit` | der Schritt ist berechnet, nichts gespeichert |
| `runner.after_commit` | der Schritt ist gespeichert, die Türklingel nicht betätigt |
| `effect.after_claim` | der Effekt ist als laufend markiert, der Handler nicht aufgerufen |
| `effect.after_call` | der Handler ist zurückgekehrt, das Ergebnis nicht gespeichert |
| `effect.after_record` | das Ergebnis ist gespeichert |
| `reconcile.after_call` | ein Abgleich ist zurückgekehrt, nicht gespeichert |
| `ticker.after_fire` | ein Timer ist als ausgelöst markiert, sein Ereignis nicht eingereiht |

Ein Absturz rollt die offene Transaktion zurück und hinterlässt Leases so, wie
es ein getöteter Worker täte. Prüfen Sie nach `recover()` die Invarianten, die
für Sie wichtig sind:

```php
it('never charges twice when the worker dies mid-call', function () {
    $t = (new ProcessTest)
        ->fake(ChargeCard::class, returns: 'tx-1', crash: 'after-call', reconcile: Outcome::succeeded('tx-1'))
        ->begin(OrderFlow::class)
        ->send('submit', ['items' => ['sku-1']]);

    $t->recover()
        ->assertCalls(ChargeCard::class, 1)
        ->assertContext('paymentId', 'tx-1')
        ->assertIn('shipping');
});
```

Die eigene Suite der Engine durchläuft jeden Fehlerpunkt bei mehreren Treffern
in zwei Szenarien und prüft: keine ausstehenden Ereignisse übrig, höchstens ein
Aufruf pro at-most-once-Schlüssel, eindeutige Effektschlüssel, ein Schritt pro
externem Ereignis und dass das Falten der Kontext-Patches des Journals den
gespeicherten Kontext ergibt.

## Race Conditions

Zwei Engines auf einem Store mit unterschiedlichen Worker-IDs modellieren zwei
Worker:

```php
$store = new InMemoryStore;
$clock = new VirtualClock;
$a = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'A');
$b = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'B');
```

Die Suite der Laravel-Bridge ergänzt echte Prozesse (`pcntl_fork`) gegen
SQLite, MySQL und PostgreSQL sowie einen Worker, der mitten im Aufruf mit
SIGKILL beendet wird.
