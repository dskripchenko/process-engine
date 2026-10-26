# Instanzen betreiben

## Eine Instanz ansehen

```php
$view = $engine->get($instanceId);     // or find() for null when missing

$view->status();          // running | suspended | completed | failed | cancelled
$view->active();          // every active state, outermost first
$view->leaves();          // where it stands
$view->is('review');      // also true for the final state of a finished instance
$view->label();           // client-facing status: meta.label of the innermost labelled state
$view->expects();         // events accepted now (guards on the context evaluated), timers
$view->context();         // the context object (or array); contextArray() for the array
$view->final(); $view->output();
$view->timers();          // pending, with fireAt; overdueTimers() for late ones
$view->subscriptions();   // correlation keys it listens on
$view->pendingEffects();  // running, waiting, unknown
$view->incidents();       // open ones
$view->children();
$view->journal();         // the history
$view->why();             // the journal entry that brought it here
$view->toArray();
```

`expects()` wertet Guards aus, die nur vom Kontext abhängen; Ereignisse, deren
Guard das Ereignis liest, werden als `conditional` markiert. Verwenden Sie es,
um die Aktionen anzuzeigen, die ein Kunde gerade ausführen kann.

Listen und Zähler:

```php
use Dskripchenko\ProcessEngine\Store\InstanceQuery;

$engine->instances(new InstanceQuery(state: 'awaitingDocuments', definitionName: 'claim-settlement'));
$engine->instances(new InstanceQuery(hasIncidents: true));
$engine->stateCounts(ClaimSettlement::class);   // state => instances, for an overview map
$engine->openIncidents();
$engine->health();   // running, suspended, with_incidents, open_incidents, overdue_timers, expired_effect_leases
```

## Das Journal

Jeder Eintrag hat einen Typ, Daten, den Akteur, die Begründung und einen
Zeitpunkt. Die wichtigsten Typen:

| Typ | Daten |
|---|---|
| `started`, `adopted`, `migrated` | Version, Kontext, Zustände |
| `step` | das Ereignis und sein Payload, genommene Übergänge (jeweils mit dem Guard, der sie erlaubt hat), von ihren Guards abgelehnte Kandidaten, verlassene und betretene Zustände, der Kontext-Patch (RFC 6902), Notizen, Befehle |
| `event.ignored`, `event.deferred`, `event.discarded` | warum, und was erwartet wurde |
| `effect.succeeded`, `effect.retry`, `effect.failed`, `effect.unknown`, `effect.reconciled`, `effect.lease_expired`, `effect.compensated`, `effect.orphaned` | Schlüssel, Klasse, Versuch, Ergebnis oder Fehler |
| `timer.fired`, `poll.result`, `child.started` | |
| `incident.opened` | Typ, Meldung |
| `manual.*`, `manual.rejected` | die Operation, Parameter, Akteur, Begründung |
| `completed`, `failed`, `cancelled` | Endzustand, Ausgabe |

Payloads werden so gespeichert, wie sie gesendet wurden: „Wer hat was
eingegeben“ steht im Journal.

## Operatoraktionen

Jede Aktion benötigt einen Akteur und eine **Begründung**. Aktionen laufen über
die Inbox, werden also mit gewöhnlichen Ereignissen serialisiert und landen im
Journal.

```php
$by = Actor::operator($user->id, $user->name);

$engine->transitionTo($id, ['awaitingParty'], $by, 'the insurer confirmed by phone');
$engine->transitionTo($id, ['review'], $by, 'reset', runExit: false, runEntry: false);
$engine->patchContext($id, [['op' => 'replace', 'path' => '/externalId', 'value' => 'APP-7']], $by, 'typo');
$engine->suspend($id, $by, 'fraud investigation');    // events wait, effects do not start
$engine->resume($id, $by, 'cleared');
$engine->cancel($id, $by, 'client withdrew');
$engine->cancel($id, $by, 'client withdrew', compensate: true);

$engine->retryEffect($effectId, $by, 'the provider is back');
$engine->retryEffect($effectId, $by, 'the bank confirmed nothing was charged', confirmUnsafe: true);
$engine->resolveEffect($effectId, $by, 'found the transfer in the bank statement', succeeded: true, result: 'TR-55');
$engine->resolveEffect($effectId, $by, 'the transfer was rejected', succeeded: false, code: 'rejected');
$engine->skipEffect($effectId, $by, 'sent by hand', result: null);
$engine->resolveIncident($incidentId, $by, 'handled');
$engine->redeliver($id, $inboxId, $by, 'retry after the fix');   // re-run an event whose step threw
```

- `transitionTo` ignoriert Guards. Exit- und Entry-Aktionen laufen, sofern sie
  nicht abgeschaltet werden.
- `resolveEffect` / `skipEffect` stellen das übliche `done`- / `error`-Ereignis
  zu, sodass der Prozess fortfährt, als wäre der Aufruf zurückgekehrt.
- `retryEffect` auf einen unbekannten at-most-once-Effekt wird ohne
  `confirmUnsafe: true` abgelehnt.
- Eine pausierte Instanz akzeptiert weiterhin Operatoraktionen; ihre Ereignisse
  warten.

Autorisierung ist Sache des Hosts: Prüfen Sie Berechtigungen, bevor Sie diese
Methoden aufrufen.

## Incidents

| Typ | Wann |
|---|---|
| `effect_exhausted` | ein Effekt hat mit `onExhausted: 'incident'` seine Versuche aufgebraucht |
| `effect_failed` | ein Fehler, den niemand behandelt hat (kein `onError`), ein Fire-and-Forget-Effekt ist fehlgeschlagen |
| `effect_unknown` | ein at-most-once-Ergebnis konnte nicht geklärt werden |
| `compensation_failed`, `compensation_blocked` | Rückgängigmachen fehlgeschlagen / wartet auf ein unbekanntes Ergebnis |
| `step_error` | ein Guard oder eine Aktion hat eine Exception geworfen: das Ereignis wird als fehlgeschlagen markiert, `redeliver()` nach der Korrektur |
| `microstep_limit` | ereignislose Übergänge laufen in einer Schleife |
| `handler_missing` | eine Effektklasse, auf die eine lebende Version verweist, ist verschwunden |
| `context_too_large` | der Kontext überschreitet die Grenze |
| `poll_failing` | ein Poll schlägt wiederholt fehl |

Eine Instanz mit offenen Incidents wird markiert (`hasIncidents`) und bleibt
außerhalb des Archivs; der Rest von ihr arbeitet weiter, wo er kann.

## Signale

Übergeben Sie der Engine einen `EventListener`, um nach dem Commit Signale zu
erhalten: `instance.started`, `step.committed`, `event.ignored`,
`effect.succeeded`, `effect.failed`, `effect.unknown`, `incident.opened`,
`incident.resolved`, `instance.completed`, `instance.failed`,
`instance.cancelled`. Die Laravel-Bridge wandelt sie in
`ProcessSignal`-Ereignisse um.

## Die Laufzeitumgebung antreiben

| Aufruf | Wer ruft ihn auf |
|---|---|
| `runInstance($id)` | ein Worker, wenn eine Instanz Ereignisse hat (in Laravel ein Queue-Job) |
| `executeEffect($id)` | ein Worker, wenn ein Effekt fällig ist |
| `tick()` | jede Minute: löst Timer aus, stellt Effekte toter Worker wieder her, klingelt verlorene Arbeit erneut an, löscht abgelaufene Nachrichten |
| `archive()` | täglich: verschiebt abgeschlossene Instanzen, die älter als 30 Tage sind, ins Archiv |
| `drain()` | synchroner Modus (Tests, Skripte): führt alles Angeklingelte aus, bis nichts mehr zu tun ist |

## Archiv

Abgeschlossene Instanzen ohne offene Incidents oder lebende Kindprozesse wandern
nach `archiveAfter` (standardmäßig 30 Tage) ins Archiv: eine Zeile pro Instanz
mit komprimiertem Snapshot, Journal, Effekten und Incidents. `get()` gibt sie
weiterhin zurück (`isArchived()`), schreibgeschützt.
