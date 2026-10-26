# 使用 @dskripchenko/flow 绘图

[@dskripchenko/flow](https://github.com/dskripchenko/flow) 用于绘制流程图，支持实时
监控和逐层下钻。引擎导出的正是它的格式——Graph v1 和 MonitorSnapshot——因此无需任何
适配器。

```php
use Dskripchenko\ProcessEngine\Export\FlowExporter;

$exporter = new FlowExporter;

$graph    = $exporter->graph($definition);                       // the whole graph, sub-levels inline
$level    = $exporter->graph($definition, ['review']);           // one drill-down level
$snapshot = $exporter->snapshot($engine->get($id), path: []);    // an instance on a level
$overview = $exporter->overview($definition, $engine->stateCounts(ClaimSettlement::class));
```

`$view->definition()` 返回实例的定义（即其自身的版本：旧实例会在其旧图上绘制）。

## 状态如何映射为节点

| 状态图 | Flow 节点 |
|---|---|
| 某一层的初始状态 | 一个 `start` 节点和一条边 |
| 原子状态 | `state` |
| 调用副作用的状态 | `task`（`data.effect`、`data.forEach`） |
| 终态 | `end` |
| 选择 | `decision` |
| 复合 / 并行状态 | 带内联 `subgraph` 的 `subprocess`（并行状态：区域作为 `groups`，`data.kind: parallel`，`data.join`） |
| 调用子流程的状态 | 带引用 `name@version` 的 `subprocess`（用 `loadSubgraph` 解析） |
| `spawn` | 通过 `association` 边连接的 `subprocess` 节点 |
| 轮询、定时器 | `data.poll`、`data.timers` |
| `meta.label`, `meta.description` | `label`、`description`；其他 meta 放入 `data` |

边以 `label` 携带事件（定时器为 `⏱ 10 days`，调用结果为 `done` / `error`），以
`condition` 携带守卫（路由表为 `status = 'paid'`，方法守卫为方法名）。在某一层本身
声明的转换（`from: '*'`）从该层的 start 节点出发，标记为“(any state)”。

## 实例如何映射为快照

| 事实 | 节点状态 |
|---|---|
| 活动并等待事件 | `waiting`，`message` 列出它在等待什么 |
| 活动且有执行中的副作用 | `active` |
| 之前访问过 | `done`，多次进入时（循环）带 `count` |
| 有结果未知的副作用 | `unknown`（用 `statusTones: { unknown: 'warning' }` 映射） |
| 其副作用上有未关闭的事故 | `error`，`badge` = 事故数量，`message` |

`path` 按进入顺序列出该层的状态，`current` 列出活动状态，`edges[id].traversed`
表示已执行的转换。

## 在 FlowView 中

```vue
<FlowView
  :graph="graph"
  :state="initialSnapshot"
  :poll="{ intervalMs: 5000, fetch: (signal, ctx) => fetch(`/processes/${id}/state?path=${ctx.path.join(',')}`, { signal }).then(r => r.json()) }"
  :status-tones="{ unknown: 'warning', compensated: 'neutral' }"
/>
```

该端点以 `$exporter->snapshot($engine->get($id), $path)` 作为响应。
在 laravel-admin 中，`Layout::flow()` 接收图以及该页面的一个轮询方法，后者返回相同的
快照。

## Mermaid

```php
use Dskripchenko\ProcessEngine\Export\MermaidExporter;

echo (new MermaidExporter)->export($definition);   // stateDiagram-v2
```

用于文档和评审（artisan `process-engine:graph`）。
