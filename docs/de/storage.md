# Storage-Adapter

Die Engine persistiert über eine einzige Schnittstelle,
`Dskripchenko\ProcessEngine\Store\Store`. Der Kern liefert `InMemoryStore`
(Tests, Skripte); die Laravel-Bridge liefert einen Datenbank-Store für
PostgreSQL, MySQL / MariaDB und SQLite. Schreiben Sie einen eigenen, um auf
etwas anderem zu laufen.

## Was der Vertrag verlangt

- **`transaction(callable)`** — die Arbeitseinheit eines Schritts: Alles, was
  darin geschrieben wird, wird gemeinsam committet oder gar nicht;
  verschachtelte Aufrufe schließen sich der äußeren Transaktion an.
- **Fencing** — `updateInstance($record, $expectedSeq)` schreibt nur, wenn das
  gespeicherte `seq` gleich `$expectedSeq` ist, und gibt andernfalls `false`
  zurück. Das macht einen Worker mit abgelaufener Lease harmlos.
- **Compare-and-Set** — `acquireLease()`, `claimEffect()` und `fireTimer()`
  sind atomare bedingte Updates (`UPDATE … WHERE status = ? …`), die eigenständig
  committet werden.
- **Idempotente Inserts** — `insertInbox()` (eindeutig pro Instanz und
  Schlüssel), `insertEffect()` und `insertTimer()` (eindeutiger Schlüssel),
  `insertMessage()` (eindeutig nach Ereignis + Korrelationsschlüssel +
  Schlüssel), `insertInstance()` (eindeutige ID und Geschäftsschlüssel
  innerhalb eines Prozesses) geben bei einem Duplikat `false` zurück, statt eine
  Exception zu werfen.
- **Reihenfolge** — `nextInbox()` gibt ausstehende Ereignisse in
  Eingangsreihenfolge zurück; `appendJournal()` vergibt eine aufsteigende `seq`.
- **Abfragen** — Instanzen nach Status, Prozess, Version, Zustand,
  Geschäftsschlüssel, Subjekt, Incidents; fällige Timer und Effekte; abgelaufene
  Leases; archivierbare Instanzen.

Es ist kein datenbankspezifisches Locking nötig: Bedingte Updates und
eindeutige Schlüssel reichen auf jeder SQL-Datenbank aus.

## Tabellen (der Datenbank-Store)

| Tabelle | Enthält |
|---|---|
| `definitions` | gespeicherte Versionen: Name, Version, Hash, kanonisches JSON |
| `instances` | Snapshot, seq, Lease, Elternprozess, Geschäftsschlüssel, Subjekt |
| `active_states` | aktive Zustände als Zeilen, für indizierte Abfragen „Instanzen im Zustand X“ |
| `inbox` | Ereignisse mit ihren Schlüsseln und ihrem Status |
| `effects` | die Outbox: Schlüssel, Klasse, Semantik, Richtlinie, Status, Versuch, Lease, Ergebnis |
| `timers` | Schlüssel, Ereignis, Zeitpunkt, Status |
| `subscriptions`, `messages` | Korrelation und der Puffer früher Ereignisse |
| `incidents`, `journal` | |
| `archive` | ein komprimiertes Dokument pro abgeschlossener Instanz |

## Skalierung

Das Design zielt auf rund 100.000 aktive Instanzen und 10 Millionen
archivierte: Heiße Tabellen enthalten nur aktive und kürzlich abgeschlossene
Instanzen, und eine archivierte Instanz ist eine einzige Zeile. Journale aktiver
Instanzen werden über `(instance_id, seq)` paginiert.

## Hinweise zu SQLite

Konfigurieren Sie die Verbindung bei mehreren Workern mit
`transaction_mode = IMMEDIATE` und WAL: Deferred-Transaktionen, die zu
Schreibvorgängen hochgestuft werden, schlagen mit „database is locked“ fehl,
statt zu warten. Für einen Worker oder Tests sind die Standardeinstellungen in
Ordnung.
