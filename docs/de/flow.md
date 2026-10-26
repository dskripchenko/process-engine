# Zeichnen mit @dskripchenko/flow

[@dskripchenko/flow](https://github.com/dskripchenko/flow) zeichnet
Prozessgraphen mit Live-Monitoring und Drill-down. Die Engine exportiert genau
dessen Formate — Graph v1 und MonitorSnapshot —, sodass kein Adapter nötig ist.

```php
use Dskripchenko\ProcessEngine\Export\FlowExporter;

$exporter = new FlowExporter;

$graph    = $exporter->graph($definition);                       // the whole graph, sub-levels inline
$level    = $exporter->graph($definition, ['review']);           // one drill-down level
$snapshot = $exporter->snapshot($engine->get($id), path: []);    // an instance on a level
$overview = $exporter->overview($definition, $engine->stateCounts(ClaimSettlement::class));
```

`$view->definition()` liefert die Definition einer Instanz (in ihrer eigenen
Version: Alte Instanzen werden auf ihrem alten Graphen gezeichnet).

## Wie Zustände auf Knoten abgebildet werden

| Statechart | Flow-Knoten |
|---|---|
| Anfangszustand einer Ebene | ein `start`-Knoten und eine Kante |
| atomarer Zustand | `state` |
| Zustand, der einen Effekt aufruft | `task` (`data.effect`, `data.forEach`) |
| Endzustand | `end` |
| Verzweigung | `decision` |
| zusammengesetzter / paralleler Zustand | `subprocess` mit einem eingebetteten `subgraph` (parallel: Regionen als `groups`, `data.kind: parallel`, `data.join`) |
| Zustand, der einen Kindprozess aufruft | `subprocess` mit einer Referenz `name@version` (aufzulösen mit `loadSubgraph`) |
| `spawn` | ein `subprocess`-Knoten, verbunden über eine `association`-Kante |
| Polls, Timer | `data.poll`, `data.timers` |
| `meta.label`, `meta.description` | `label`, `description`; übrige Metadaten gehen nach `data` |

Kanten tragen das Ereignis als `label` (`⏱ 10 days` für Timer, `done` / `error`
für Aufrufergebnisse) und den Guard als `condition` (`status = 'paid'` für
Routing-Tabellen, den Methodennamen für Methoden-Guards). Auf einer Ebene selbst
deklarierte Übergänge (`from: '*'`) beginnen an ihrem Startknoten und sind mit
„(any state)“ beschriftet.

## Wie eine Instanz auf einen Snapshot abgebildet wird

| Sachverhalt | Knotenzustand |
|---|---|
| aktiv und wartet auf ein Ereignis | `waiting`, `message` listet auf, worauf er wartet |
| aktiv mit laufenden Effekten | `active` |
| zuvor besucht | `done`, `count`, wenn mehr als einmal betreten (Schleifen) |
| ein Effekt mit unbekanntem Ergebnis | `unknown` (zuzuordnen mit `statusTones: { unknown: 'warning' }`) |
| ein offener Incident bei seinen Effekten | `error`, `badge` = Anzahl der Incidents, `message` |

`path` listet die Zustände der Ebene in der Reihenfolge ihres Betretens auf,
`current` die aktiven, `edges[id].traversed` die genommenen Übergänge.

## In FlowView

```vue
<FlowView
  :graph="graph"
  :state="initialSnapshot"
  :poll="{ intervalMs: 5000, fetch: (signal, ctx) => fetch(`/processes/${id}/state?path=${ctx.path.join(',')}`, { signal }).then(r => r.json()) }"
  :status-tones="{ unknown: 'warning', compensated: 'neutral' }"
/>
```

Der Endpunkt antwortet mit `$exporter->snapshot($engine->get($id), $path)`.
In laravel-admin nimmt `Layout::flow()` den Graphen und eine Poll-Methode des
Screens entgegen, die denselben Snapshot zurückgibt.

## Mermaid

```php
use Dskripchenko\ProcessEngine\Export\MermaidExporter;

echo (new MermaidExporter)->export($definition);   // stateDiagram-v2
```

Für Dokumentation und Reviews (Artisan `process-engine:graph`).
