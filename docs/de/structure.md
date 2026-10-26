# Struktur: Verschachtelung, parallele Regionen, History, Kindprozesse

Zwei Arten von Struktur mit unterschiedlichen Kosten: innerhalb einer Instanz
(zusammengesetzte und parallele Zustände) und über Instanzen hinweg
(Kindprozesse).

## Zusammengesetzte Zustände

```php
$p->state('application', fn (Builder $s) => $s
    ->after('14 days', to: 'abandoned')              // applies to every state inside
    ->on('client.cancelled', to: 'cancelled')        // so does this
    ->state('filling', fn (Builder $s) => $s->on(FormSubmitted::class, to: 'signing'))
    ->state('signing', fn (Builder $s) => $s->on('client.signed', to: 'signed'))
    ->final('signed')
    ->onDone(to: 'review'));
```

- Kinder teilen sich den Kontext und das Journal.
- Am zusammengesetzten Zustand deklarierte Übergänge wirken aus jedem Zustand
  darin: Ein Timer an `application` ist eine Frist für die gesamte Phase.
- Das Erreichen eines End-Kindzustands löst `done.state.<id>` aus; `onDone()`
  reagiert darauf.

## Parallele Zustände

```php
$p->parallel('agreement', fn (Builder $s) => $s
    ->region('compensation', fn (Builder $r) => $r
        ->state('offering', fn (Builder $s) => $s->on('client.accepted', to: 'agreed'))
        ->final('agreed', output: 'compensation'))
    ->region('service', fn (Builder $r) => $r
        ->state('choosingSlot', fn (Builder $s) => $s->on('client.slot-chosen', to: 'booked'))
        ->final('booked'))
    ->onDone(to: 'route'), join: 'all');
```

- `join: 'all'` (Standard): abgeschlossen, wenn jede Region ihren Endzustand
  erreicht hat; das `done`-Ereignis trägt die Ausgabe jeder Region.
- `join: 'any'`: abgeschlossen, wenn die erste Region fertig ist; die anderen
  Regionen werden verlassen, ihre ausstehenden Effekte und Kindprozesse
  abgebrochen.
- Regionen sind logisch parallel und physisch sequenziell (eine Lease pro
  Instanz); ihre Effekte laufen tatsächlich parallel.

## History

```php
$p->state('work', fn (Builder $s) => $s
    ->on('pause', to: 'paused')
    ->state('step1', /* … */)
    ->state('step2', /* … */)
    ->history('resume')                 // shallow: the last active child
    ->history('resumeDeep', deep: true)); // deep: the exact nested states
$p->state('paused', fn (Builder $s) => $s->on('continue', to: 'resume'));
```

Das Betreten eines History-Zustands stellt die Konfiguration wieder her, die der
Elternzustand beim letzten Verlassen hatte (beim ersten Mal den
Anfangszustand des Elternzustands oder `default:`).

## Kindprozesse

Ein Zustand kann einen anderen Prozess als separate Instanz ausführen:

```php
->invoke(DocumentRequest::class,
    input: $this->requestInput(...),
    onDone: ['signed' => 'review', 'refused' => 'refused', 'expired' => 'refused'],
    onError: 'failed')
```

- Der Kindprozess hat seine eigene Version (beim Start festgelegt), seinen
  eigenen Kontext, sein Journal und seine Lease. Er sieht nur seinen `input`;
  der Elternprozess sieht nur seinen `output` (`final('signed', output: 'files')`).
- `onDone` mit einer Zuordnung routet nach dem Endzustand des Kindprozesses; der
  Builder prüft, dass jeder Endzustand des Kindprozesses geroutet ist.
- Verlässt der Elternprozess den Zustand, wird der Kindprozess abgebrochen. Ein
  fehlgeschlagener oder abgebrochener Kindprozess sendet `error.invoke.<id>` mit
  dem Code `child_failed` / `child_cancelled`.

### Ein Kindprozess pro Element

```php
->invoke(SignDocument::class, forEach: 'documents', into: 'signatures', onDone: 'done')
```

N Kindprozesse, Ergebnisse in der Reihenfolge der Liste gesammelt,
abgeschlossen, wenn alle fertig sind.

### Ein Kindprozess pro Ereignis: `spawn`

```php
$p->on(NotificationReceived::class, spawn: ClaimRequest::class, input: $this->requestInput(...), key: 'messageId');
```

- Für jedes Ereignis startet ein Kindprozess, ohne Übergang des Elternprozesses.
- `key` (ein Ereignisfeld) dedupliziert: Derselbe Wert startet nie einen zweiten
  Kindprozess.
- Der Kindprozess lebt so lange wie der Gültigkeitsbereich, in dem `spawn`
  deklariert ist (ein Zustand oder der gesamte Prozess); `onScopeExit: 'keep'`
  lässt ihn den Gültigkeitsbereich überdauern.
- Sein Abschluss kommt als `done.spawn.<id>` an; eine Reaktion ist optional.
  `StepContext::spawned()` zählt die lebenden für Guards.

## Was verwenden

| Bedarf | Verwenden |
|---|---|
| Phasen eines Prozesses, gemeinsamer Kontext, „von überall in der Phase“ | zusammengesetzter Zustand |
| gleichzeitig laufende Zweige, die zusammengeführt werden | paralleler Zustand |
| ein wiederverwendbarer Baustein mit eigener Version und eigenem Lebenszyklus | Kindprozess |
| N gleichartige Zweige aus Daten | `invoke(..., forEach:)` eines Kindprozesses |
| N gleichartige externe Aufrufe aus Daten | `invoke(..., forEach:)` eines Effekts |
| Teilprozesse, die als Ereignisse in unbekannter Anzahl eintreffen | `spawn` |
