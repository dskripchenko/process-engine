# Drawing with @dskripchenko/flow

[@dskripchenko/flow](https://github.com/dskripchenko/flow) draws process
graphs with live monitoring and drill-down. The engine exports exactly its
formats — Graph v1 and MonitorSnapshot — so no adapter is needed.

```php
use Dskripchenko\ProcessEngine\Export\FlowExporter;

$exporter = new FlowExporter;

$graph    = $exporter->graph($definition);                       // the whole graph, sub-levels inline
$level    = $exporter->graph($definition, ['review']);           // one drill-down level
$snapshot = $exporter->snapshot($engine->get($id), path: []);    // an instance on a level
$overview = $exporter->overview($definition, $engine->stateCounts(ClaimSettlement::class));
```

`$view->definition()` gives the definition of an instance (its own version:
old instances are drawn on their old graph).

## How states map to nodes

| Statechart | Flow node |
|---|---|
| initial state of a level | a `start` node and an edge |
| atomic state | `state` |
| state invoking an effect | `task` (`data.effect`, `data.forEach`) |
| final state | `end` |
| choice | `decision` |
| compound / parallel state | `subprocess` with an inline `subgraph` (parallel: regions as `groups`, `data.kind: parallel`, `data.join`) |
| state invoking a child process | `subprocess` with a reference `name@version` (resolve it with `loadSubgraph`) |
| `spawn` | a `subprocess` node linked by an `association` edge |
| polls, timers | `data.poll`, `data.timers` |
| `meta.label`, `meta.description` | `label`, `description`; other meta goes to `data` |

Edges carry the event as `label` (`⏱ 10 days` for timers, `done` / `error`
for invocation results) and the guard as `condition` (`status = 'paid'` for
routing tables, the method name for method guards). Transitions declared on a
level itself (`from: '*'`) start at its start node, labelled "(any state)".

## How an instance maps to a snapshot

| Fact | Node state |
|---|---|
| active and waiting for an event | `waiting`, `message` lists what it waits for |
| active with effects in flight | `active` |
| visited before | `done`, `count` when entered more than once (loops) |
| an effect with an unknown outcome | `unknown` (map it with `statusTones: { unknown: 'warning' }`) |
| an open incident on its effects | `error`, `badge` = number of incidents, `message` |

`path` lists the states of the level in the order they were entered,
`current` the active ones, `edges[id].traversed` the transitions taken.

## In FlowView

```vue
<FlowView
  :graph="graph"
  :state="initialSnapshot"
  :poll="{ intervalMs: 5000, fetch: (signal, ctx) => fetch(`/processes/${id}/state?path=${ctx.path.join(',')}`, { signal }).then(r => r.json()) }"
  :status-tones="{ unknown: 'warning', compensated: 'neutral' }"
/>
```

The endpoint answers with `$exporter->snapshot($engine->get($id), $path)`.
In laravel-admin, `Layout::flow()` takes the graph and a poll method of the
screen that returns the same snapshot.

## Mermaid

```php
use Dskripchenko\ProcessEngine\Export\MermaidExporter;

echo (new MermaidExporter)->export($definition);   // stateDiagram-v2
```

For documentation and reviews (artisan `process-engine:graph`).
