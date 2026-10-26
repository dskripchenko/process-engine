# Отрисовка с @dskripchenko/flow

[@dskripchenko/flow](https://github.com/dskripchenko/flow) рисует графы
процессов с живым мониторингом и детализацией по уровням. Движок экспортирует
ровно его форматы — Graph v1 и MonitorSnapshot, — поэтому адаптер не нужен.

```php
use Dskripchenko\ProcessEngine\Export\FlowExporter;

$exporter = new FlowExporter;

$graph    = $exporter->graph($definition);                       // the whole graph, sub-levels inline
$level    = $exporter->graph($definition, ['review']);           // one drill-down level
$snapshot = $exporter->snapshot($engine->get($id), path: []);    // an instance on a level
$overview = $exporter->overview($definition, $engine->stateCounts(ClaimSettlement::class));
```

`$view->definition()` даёт описание экземпляра (его собственной версии:
старые экземпляры рисуются на своём старом графе).

## Как состояния отображаются в узлы

| Машина состояний | Узел flow |
|---|---|
| начальное состояние уровня | узел `start` и ребро |
| атомарное состояние | `state` |
| состояние, вызывающее эффект | `task` (`data.effect`, `data.forEach`) |
| финальное состояние | `end` |
| выбор | `decision` |
| составное / параллельное состояние | `subprocess` со встроенным `subgraph` (параллельное: регионы как `groups`, `data.kind: parallel`, `data.join`) |
| состояние, вызывающее дочерний процесс | `subprocess` со ссылкой `name@version` (разрешается через `loadSubgraph`) |
| `spawn` | узел `subprocess`, связанный ребром `association` |
| опросы, таймеры | `data.poll`, `data.timers` |
| `meta.label`, `meta.description` | `label`, `description`; прочие meta попадают в `data` |

Рёбра несут событие как `label` (`⏱ 10 days` для таймеров, `done` / `error`
для результатов вызовов) и гард как `condition` (`status = 'paid'` для таблиц
маршрутизации, имя метода для гардов-методов). Переходы, объявленные на самом
уровне (`from: '*'`), начинаются в его стартовом узле с подписью «(any state)».

## Как экземпляр отображается в снапшот

| Факт | Состояние узла |
|---|---|
| активно и ждёт события | `waiting`, в `message` перечислено, чего оно ждёт |
| активно, эффекты в работе | `active` |
| посещено раньше | `done`, `count`, если вход был больше одного раза (циклы) |
| эффект с неизвестным исходом | `unknown` (сопоставьте через `statusTones: { unknown: 'warning' }`) |
| открытый инцидент по его эффектам | `error`, `badge` = число инцидентов, `message` |

`path` перечисляет состояния уровня в порядке входа, `current` — активные,
`edges[id].traversed` — выполненные переходы.

## Во FlowView

```vue
<FlowView
  :graph="graph"
  :state="initialSnapshot"
  :poll="{ intervalMs: 5000, fetch: (signal, ctx) => fetch(`/processes/${id}/state?path=${ctx.path.join(',')}`, { signal }).then(r => r.json()) }"
  :status-tones="{ unknown: 'warning', compensated: 'neutral' }"
/>
```

Эндпоинт отвечает результатом `$exporter->snapshot($engine->get($id), $path)`.
В laravel-admin `Layout::flow()` принимает граф и метод опроса экрана,
который возвращает тот же снапшот.

## Mermaid

```php
use Dskripchenko\ProcessEngine\Export\MermaidExporter;

echo (new MermaidExporter)->export($definition);   // stateDiagram-v2
```

Для документации и ревью (artisan `process-engine:graph`).
