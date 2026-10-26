# Konzepte und Garantien

## Das Modell: Statecharts mit explizitem Graphen

Eine Prozessdefinition ist ein **Statechart**: Zustände, die verschachtelt sind
(zusammengesetzt), Zustände, die nebeneinander laufen (parallele Regionen),
Endzustände, Verzweigungen und History. Übergänge reagieren auf Ereignisse,
können einen Guard und Aktionen haben und wechseln zwischen Zuständen. Die
Semantik eines Schritts folgt dem W3C-SCXML-Algorithmus: Übergänge werden von
den tiefsten aktiven Zuständen nach oben ausgewählt, Konflikte werden
aufgelöst, Zustände werden in umgekehrter Dokumentreihenfolge verlassen und in
Dokumentreihenfolge betreten, ereignislose Übergänge und interne Ereignisse
laufen, bis sich nichts mehr ändert.

Der Graph ist **Daten**. Eine `Process`-Klasse erzeugt eine kanonische
JSON-Form; diese Form wird gehasht, pro Version gespeichert und ausgeführt. Da
der Graph explizit ist, kann die Engine ihn zeichnen, angeben, welche
Ereignisse eine Instanz gerade akzeptiert, eine Instanz auf Anforderung eines
Operators in einen beliebigen Zustand versetzen und alte Instanzen auf ihrer
Version belassen.

## Ein Schritt ist eine reine Funktion

```
step(definition, snapshot, event, time) → (snapshot', commands, journal data)
```

Der Interpreter hat keinen Speicher, keine Uhr und keine Seiteneffekte.
Effekte, Timer, Polls, Abonnements und Kindprozesse kommen als **Befehle**
heraus. Die Laufzeitumgebung schreibt dann **in einer Datenbanktransaktion**:
den neuen Snapshot (gegen seine Version geprüft), den Journaleintrag, die
Befehle (Outbox-Zeilen, Timer, Abonnements, Kindprozesse) und die
„verarbeitet“-Markierung des Ereignisses. Entweder wird alles gespeichert oder
nichts.

Guards und Aktionen in der Prozessklasse erhalten keine Services und führen
kein I/O aus. Sie sehen den Kontext, das Ereignis und den Zeitpunkt des
Schritts. Alles, was mit der Welt kommuniziert, ist ein Effekt und wird
**nach** dem Commit des Schritts ausgeführt.

## Alles kommt über die Inbox herein

Externe Ereignisse, ausgelöste Timer, Effektergebnisse, Abschlüsse von
Kindprozessen und Operatoraktionen sind Zeilen in der Inbox der Instanz, jede
mit einem **Idempotenzschlüssel**. Ein wiederholter Schlüssel wird als Duplikat
gemeldet und bewirkt nichts. Ereignisse einer Instanz werden einzeln in
Eingangsreihenfolge verarbeitet.

## Leases und Fencing

Ein Worker nimmt eine kurze **Lease** auf eine Instanz, bevor er sie
weiterschaltet, und arbeitet ihre Inbox ab. Jeder Schritt wird nur committet,
wenn die Sequenznummer des Snapshots noch die gelesene ist; ein Worker, dessen
Lease abgelaufen ist (eine lange Pause, eine Netzwerktrennung), stellt fest,
dass die Instanz weitergelaufen ist, und macht ein Rollback. Während der
Effekte werden keine Datenbanksperren gehalten, und es ist kein
datenbankspezifisches Locking nötig.

## Die Queue ist nur eine Türklingel

Erzeugt ein Schritt Arbeit, „klingelt“ die Laufzeitumgebung nach dem Commit bei
einem Dispatcher (in Laravel ein Queue-Job). Ein verlorenes Klingeln verliert
nichts: Der periodische **Tick** löst fällige Timer aus, stellt Effekte wieder
her, deren Worker gestorben ist, und klingelt bei Instanzen mit ausstehenden
Ereignissen erneut. Lange Wartezeiten (Tage, Wochen) sind Timer-Zeilen, keine
verzögerten Queue-Jobs.

## Garantien

| Was | Garantie |
|---|---|
| Übergänge | genau einmal pro Inbox-Ereignis (Deduplizierung + atomarer Commit + Fencing) |
| `#[Idempotent]`-Effekte | mindestens einmal; im Zweifel wiederholt |
| `#[AtMostOnce]`-Effekte | höchstens einmal pro Schlüssel; im Zweifel `unknown`, nie automatisch wiederholt |
| Reihenfolge | Ereignisse einer Instanz in Eingangsreihenfolge; keine Reihenfolge über Instanzen hinweg |
| Zeit | Timer lösen nach ihrem Zeitpunkt aus, mit der Genauigkeit des Ticks (eine Minute mit dem Scheduler, Sekunden mit einer Worker-Schleife) |

## Speicherung: Snapshot plus Journal

Der **Snapshot** (aktive Zustände, Kontext, Aktivierungszähler, History) ist
die maßgebliche Quelle für die Ausführung. Das **Journal** ist eine reine
Anhänge-Historie, die in derselben Transaktion geschrieben wird: jeder Schritt
mit dem Ereignis, den genommenen Übergängen, den Guards, die Kandidaten
abgelehnt haben, dem Kontext-Patch (RFC 6902) und dem Akteur. Das Journal wird
nie erneut abgespielt, um den Zustand wiederherzustellen, aber das Falten
seiner Kontext-Patches ergibt den gespeicherten Kontext, was die Testsuite
prüft.

Abgeschlossene Instanzen wandern in ein **Archiv**: eine komprimierte Zeile pro
Instanz mit ihrem Journal, ihren Effekten und Incidents, weiterhin über
dieselbe API lesbar.

## Aktivierungen

Jedes Betreten eines Zustands ist eine neue **Aktivierung** (`review@3`).
Schlüssel von Effekten, Timern und Abonnements tragen sie, sodass eine
Schleife, die denselben Zustand erneut betritt, frisch beginnt, und ein
verspätetes Ergebnis einer früheren Aktivierung verworfen wird, statt die
Instanz zu bewegen.

## Incidents

Wenn etwas einen Menschen braucht — ein Effekt ohne verbleibende
Wiederholungen, ein unbekanntes Ergebnis, das der Abgleich nicht klären konnte,
eine Kompensation, die immer wieder fehlschlägt, ein Schritt, der eine Exception
geworfen hat —, eröffnet die Engine einen **Incident**, und der Rest des
Prozesses arbeitet weiter, wo er kann. Incidents werden durch Operatoraktionen
geschlossen, die mit Akteur und Begründung im Journal festgehalten werden.
