# Versionen und Migrationen

## Eine Version ändert sich nie

Jede Prozessklasse deklariert `VERSION`. Beim Start einer Instanz wird die
kanonische Form dieser Version mit ihrem Hash gespeichert. Wird dieselbe
Version mit einem anderen Graphen registriert, wird
`DefinitionVersionConflict` geworfen: Graph ändern, Version erhöhen.

```php
final class ClaimSettlement extends Process
{
    public const VERSION = 2;   // was 1
    // …
}
```

(In der Entwicklung können Sie erlauben, eine Version neu zu definieren, die
noch keine Instanz verwendet: `new Engine(..., allowRedefineUnused: true)`.)

## Alte Instanzen laufen auf ihrer Version zu Ende

Eine Instanz ist an die gespeicherte Version gebunden, mit der sie gestartet
wurde, und die Engine führt **die gespeicherte Form** aus, nicht die Klasse. Sie
können `define()` für Version 2 umschreiben; Instanzen der Version 1 laufen
weiter auf dem Graphen der Version 1.

Neue Instanzen starten auf der Version, die die Klasse deklariert.
`start('claim-settlement')` per Name startet die neueste gespeicherte Version;
`start(..., version: 1)` eine bestimmte.

Was im Code bleiben muss, solange alte Versionen lebende Instanzen haben: die
**Namen**, auf die sie verweisen — Methoden der Prozessklasse, die als Guards
und Aktionen verwendet werden, Effektklassen, Kindprozessklassen. Ändern Sie
Verhalten inkompatibel unter einem neuen Namen (`ChargeV2`) und behalten Sie den
alten, bis seine Instanzen verschwunden sind.

`$engine->doctor()` (Artisan `process-engine:doctor`) listet gespeicherte
Versionen mit lebenden Instanzen auf, deren Methoden oder Klassen fehlen. Ein
fehlender Handler zur Laufzeit eröffnet einen Incident, statt die Instanz
fehlschlagen zu lassen.

## Instanzen migrieren

Migration ist explizit, pro Instanz, wird im Journal festgehalten und vorab
geprüft.

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

Eine Instanz wird nur migriert, wenn sie ruhig ist:

- sie läuft auf der Quellversion und ist nicht beendet;
- keine offenen Incidents;
- kein Effekt läuft oder hat ein unbekanntes Ergebnis;
- kein aufgerufener Kindprozess läuft;
- jeder aktive Zustand hat ein Gegenstück (zugeordnet oder mit derselben ID) im
  Ziel.

Die Anwendung unter der Lease der Instanz:

1. alles, was an die alten Aktivierungen gebunden ist, endet: ausstehende
   Effekte, Timer, Polls, Abonnements;
2. der Kontext wird transformiert;
3. die Instanz betritt die zugeordneten Zustände der neuen Version **ohne
   Entry-Aktionen** — es ist ein Umzug, kein neues Betreten; ihre Timer, Polls,
   Abonnements und Aufrufe starten (Timer zählen ab der Migration);
4. das Journal erhält einen `migrated`-Eintrag mit der Zustandszuordnung, dem
   Akteur und der Begründung.

Migrationen sind Code, der von einem Operator ausgeführt wird, daher sind
Closures hier in Ordnung. Registrieren Sie sie mit
`$engine->registerMigration()`, um sie später per Name anzuwenden
(`process-engine:migrate claim-settlement@1->2 --apply --reason=…`).

## Prozesse von außen übernehmen

`adopt()` erzeugt eine Instanz direkt in angegebenen Zuständen — für Prozesse,
die in Legacy-Code liefen, oder anderswo angelegte Datensätze:

```php
$engine->adopt(ClaimCase::class, in: ['awaitingDocuments'], context: $context,
    key: "claim:{$legacyId}", by: Actor::operator('migration'), reason: 'move from the old system');
```

Entry-Aktionen laufen nicht; Timer, Polls, Abonnements und Aufrufe der Zustände
starten. Der Schlüssel macht einen Batch-Import sicher wiederholbar.
