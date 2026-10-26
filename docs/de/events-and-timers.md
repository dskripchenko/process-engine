# Ereignisse, Timer und Polling

## Ein Ereignis an eine Instanz senden

```php
$engine->send($instanceId, new FormSubmitted($form), key: $requestId, by: Actor::user($userId));
$engine->send($instanceId, 'client.signed', ['at' => '2026-10-26'], key: 'sig-881');
```

- Ein Ereignis ist ein Objekt (seine öffentlichen Properties werden zum
  Payload; der Name ist seine Klasse oder `#[Event('name')]`) oder ein Name mit
  einem Array.
- **Der Schlüssel macht die Zustellung idempotent.** Senden Sie denselben
  Schlüssel zweimal, gibt der zweite Aufruf `Receipt::DUPLICATE` zurück. Ohne
  Schlüssel wird ein zufälliger verwendet, der nichts schützt — übergeben Sie
  einen Schlüssel für alles, was wiederholt werden kann (HTTP-Anfragen,
  Webhooks, Queue-Nachrichten).
- `by:` hält im Journal fest, wer es getan hat.
- Ein Ereignis, das der aktuelle Zustand nicht akzeptiert, wird **ignoriert**,
  und das Journal vermerkt, warum und was erwartet wurde. Asynchrone Beteiligte
  sollen Antworten nicht wegen ungünstigen Timings verlieren, daher ist das für
  den Absender kein Fehler.

`sendStrict()` prüft vorab und gibt `Receipt::REJECTED` mit den erwarteten
Ereignissen zurück, für Oberflächen, die „das Formular wurde bereits
abgeschickt“ anzeigen wollen.

### Ereignisse validieren

Eine Ereignisklasse, die `SelfValidating` implementiert, prüft sich selbst,
bevor sie eingereiht wird; der Absender erhält die Exception, der Prozess sieht
nie fehlerhafte Daten:

```php
final class FormSubmitted implements SelfValidating
{
    public function __construct(public array $form) {}

    public function validate(): void
    {
        if (empty($this->form['damage'])) {
            throw new InvalidArgumentException('Describe the damage');
        }
    }
}
```

`$engine->addValidator(fn (Event $e) => ...)` fügt eine Prüfung für jedes
Ereignis hinzu.

## Veröffentlichen per Korrelationsschlüssel

Wenn die Gegenseite nur ihre eigene Referenz kennt (eine Antragsnummer),
abonniert ein Zustand unter diesem Schlüssel:

```php
->state('awaitingParty', fn (Builder $s) => $s
    ->correlate([PartyDecision::class, DocumentsRequested::class], by: 'externalId')
    ->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...)))
```

```php
$engine->publish(new PartyDecision(approved: true), correlate: 'APP-123', key: $webhookMessageId);
```

- Das Abonnement wird beim Betreten des Zustands aus der Kontext-Property `by:`
  angelegt und beim Verlassen entfernt. Abonnieren Sie dort, wo der Schlüssel
  bereits bekannt ist.
- Ein veröffentlichtes Ereignis, auf das noch niemand wartet, wird
  **gepuffert** (standardmäßig 7 Tage) und zugestellt, sobald eine Instanz
  abonniert: Eine Antwort, die eintrifft, bevor der Prozess zu warten begonnen
  hat, geht nicht verloren.
- Duplikate (dasselbe Ereignis, derselbe Schlüssel und Korrelationsschlüssel)
  werden verworfen.

## Task-Tokens

Ein Callback, adressiert an die aktuelle Aktivierung eines Zustands:

```php
$token = $engine->taskToken($instanceId, 'awaitingSignature');   // put it into a callback URL
// later, in the callback handler:
$engine->complete($token, 'signature.completed', $payload);
```

Das Token ist signiert (HMAC mit dem Engine-Secret). Ein gefälschtes Token wirft
eine Exception; ein Token einer beendeten Aktivierung gibt `Receipt::REJECTED`
zurück.

## Zurückstellen

```php
->state('submitting', fn (Builder $s) => $s->defer(PartyDecision::class))
```

Ein zurückgestelltes Ereignis bleibt in der Inbox, bis ein Zustand aktiv ist,
der es akzeptiert (standardmäßig 30 Tage), statt ignoriert zu werden.

## Timer

```php
->after('10 days', to: 'reminded')                    // from entering the state
->after('PT2H', run: RemindClient::class)              // ISO 8601; no target: stay
->after($this->slaFor(...), to: 'escalated')           // a method returning a duration or a moment
->after(businessDays(5), to: 'late')                   // through a BusinessCalendar
->at('deadline', to: 'expired')                        // a context property with an ISO 8601 date
```

- Ein Timer gehört zu einer Aktivierung seines Zustands: Das Verlassen des
  Zustands bricht ihn ab; erneutes Betreten startet einen neuen. Ein Timer, der
  auslöst, nachdem der Zustand verlassen wurde, wird verworfen.
- Das Auslösen ist idempotent (ein Timer-Schlüssel), daher bewirkt ein doppelter
  Tick nichts.
- Dauern: `14 days`, `1 day, 2 hours`, `10s`, `2h`, `P14D`, `PT10M`.
- Werktage verwenden `BusinessCalendar` (Standard: Montag bis Freitag). Die
  Engine kennt keine Feiertage; übergeben Sie Ihren Kalender an die Engine.
- Die Genauigkeit ist die des Ticks: Führen Sie `tick()` jede Minute aus (ein
  Scheduler) oder eine Worker-Schleife für Sekunden. Timer sind für fachliche
  Fristen gedacht, nicht für Millisekunden.

## Polling

Für Beteiligte, die nicht zurückrufen können:

```php
->state('waitingForSignature', fn (Builder $s) => $s
    ->poll(CheckSignature::class, every: '1 minute')
    ->on(SignatureCompleted::class, to: 'signed')
    ->on('signature.rejected', to: 'refused')
    ->after('24 hours', to: 'expired'))
```

```php
#[Idempotent]
final class CheckSignature
{
    public function __invoke(array $context): SignatureCompleted|string|null
    {
        $state = $this->service->status($context['requestId']);

        return match ($state) {
            'complete' => new SignatureCompleted(),
            'rejected' => 'signature.rejected',
            default    => null,          // nothing new: no event, no journal entry
        };
    }
}
```

- Der Handler muss `#[Idempotent]` sein.
- `null` bedeutet „noch nichts“: kein Ereignis und **kein Journaleintrag**, nur
  Zähler. Ein Poll pro Minute über einen Tag kostet im Journal nichts.
- Ein Ereignis (Objekt oder Name) oder eine Liste von Ereignissen wird der
  Instanz zugestellt.
- `every:` kann eine Methode des Prozesses sein, die vor jedem Lauf neu
  ausgewertet wird: alle 5 Minuten pollen, solange der Kunde online ist, und
  sonst stündlich.
- Fehler folgen der Wiederholungsrichtlinie des Handlers; ist sie erschöpft,
  wird ein Incident eröffnet und das Polling mit dem maximalen Intervall
  fortgesetzt.
- Ein Ereignis kann einen zusätzlichen Lauf auslösen:
  `->on('client.opened-page', run: CheckSignature::class)`.

### Auffächern

Ein Poll (oder jeder Effekt) kann andere Instanzen adressieren:

```php
public function __invoke(array $context): array
{
    return array_map(
        fn (array $claim) => Publish::to($claim['id'], new ClaimStatusChanged($claim['status'])),
        $this->insurer->clientClaims($context['clientId']),
    );
}
```

`Publish::to($correlationKey, $event)` läuft über Abonnements und den Puffer;
`Send::to($instanceId, $event)` geht direkt in eine Inbox. Eine Anfrage für alle
Schadensfälle eines Kunden ist weit günstiger als ein Poll pro Schadensfall.
