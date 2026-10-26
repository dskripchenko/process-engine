# ADR-012. Экспорт определения и состояния для @dskripchenko/flow

- Статус: принято автором 2026-10-26
- Дата: 2026-10-26

## Контекст

`@dskripchenko/flow` 1.0.x (`~/www/forge/flow`) рисует граф и состояние
экземпляра. Его модель (`src/model/types.ts`, `schema/graph.schema.json`,
`schema/snapshot.schema.json`):

- `Graph {version: 1, nodes, edges, groups?, meta?}`;
- `FlowNode {id, type: start|end|state|task|decision|subprocess|note|<custom>,
  label, description, group, data, enabled, subgraph: string|Graph, ports}`;
- `FlowEdge {id, from, to, label, condition, enabled, kind, data}`;
- `MonitorSnapshot {nodes: {id: {status, count, badge, message, updatedAt}},
  edges: {id: {count, traversed}}, path[], current[], updatedAt}`;
  статусы `active|done|error|waiting|skipped|<custom>` (+ `statusTones`);
- drill-down: узел с `subgraph` проваливается; поллинг передаёт
  `context.path` (id пройденных узлов-подпроцессов) и `context.ref`;
- лимиты раскладки 500 узлов / 2000 рёбер на уровень.

В core 1.59.0 `Layout::flow()` принимает модель и метод-поллер, отдающий
снапшот по `{path, ref, instance}`.

## Решение

Ядро даёт `FlowExporter` с двумя функциями, результат — **ровно схемы flow**,
адаптер на JS не нужен:

```php
$graph    = $exporter->graph($definition);                         // Graph v1
$snapshot = $exporter->snapshot($instanceView, path: [], ref: null);  // MonitorSnapshot
$overview = $exporter->overview($definition, $counts);              // агрегаты по версии
```

### Отображение определения → Graph

| Statechart | flow |
|---|---|
| процесс / составное состояние | уровень графа; составное на родительском уровне — узел `subprocess` с **inline `subgraph`** (drill-down) |
| `initial` уровня | синтетический узел `start` (`id: "<level>.__start"`) + ребро в начальное состояние |
| `atomic` без `invoke` | `state` |
| `atomic` с `invoke` эффекта | `task` (`data.effect`, `data.semantics`) |
| `final` | `end` |
| `choice` | `decision` |
| `parallel` | узел `subprocess` с `data.kind: "parallel"`, `data.join`; его `subgraph` — регионы как **группы** (`groups[]`, `label` = регион) |
| `history` | `state` с `data.kind: "history"` (малый узел — через тип реестра, см. ниже) |
| дочерний процесс (`invoke: process`) | `subprocess` со **строковой ссылкой** `subgraph: "<name>@<version>"` — хост отдаёт граф через `loadSubgraph`/`subgraphs` |
| переход | ребро: `label` = событие (`form.submitted`, `⏱ P14D` для `after`, `always` — без метки), `condition` = guard (имя или краткая запись предиката), `data.actions` |
| переход, объявленный на предке | ребро от узла-предка на его уровне (как граничное событие) |
| `meta` состояния | `data` + `description` (если `meta.description`) |
| feature-flag guard `flag('x')` | `enabled` ребра/узла = текущее значение флага (ADR-007) |

| `steps` (цепочка эффектов) | узел `subprocess` с inline `subgraph`: шаги — узлы `task` по порядку |
| `invoke` с `forEach` (эффекты) | `task` с `data.forEach` (путь коллекции), в снапшоте `badge` = «готово/всего» |
| `poll` | `data.poll` у узла состояния (`every`); в снапшоте `message` = время последнего опроса |
| `spawn` | узел `subprocess` (`data.spawn`, ссылка на определение ребёнка), связанный с объявившим состоянием ребром `kind: association`; в снапшоте `count` = живые дети |
| `match` / маршрутизация по финалу | обычные рёбра; `label` = событие, `condition` = значение (`status = upload_documents`, `final = refused`) |

id узлов — полный путь состояния (`review.awaiting-documents`): уникален и
стабилен между версиями, где состояние не переименовано.

### Отображение экземпляра → MonitorSnapshot (для уровня `path`)

| Факт | Снапшот |
|---|---|
| активное атомарное состояние | `status: "active"`; в ожидании внешнего (нет эффектов в полёте) — `"waiting"`, `message` = «ждём: party.decision, ⏱ 2026-11-02» |
| активный предок (на родительском уровне) | `status: "active"` у узла-`subprocess` |
| пройденные состояния | `status: "done"`, `count` = число входов (циклы дозапросов видны счётчиком) |
| эффект `failed`/исчерпан, инцидент | `status: "error"`, `badge` = число открытых инцидентов, `message` = последняя ошибка |
| эффект `unknown` | `status: "unknown"` (кастомный, `statusTones: {unknown: 'warning'}`) |
| компенсировано | `status: "compensated"` (кастомный, `neutral`) |
| регион выключен `join: any` | `status: "skipped"` |
| рёбра, по которым ходили | `edges[id].traversed`, `count` |
| `path` | последовательность узлов уровня из журнала (по порядку входов) |
| `current` | активные узлы уровня |
| `updatedAt` | время последней записи журнала |

`overview` (обзор версии) — `count` по узлам = число экземпляров в состоянии,
`error`/`badge` там, где открыты инциденты.

### Laravel-слой / admin

Тонкий клей для `Layout::flow()`: метод экрана `processState(array $context)`
вызывает `snapshot($instance, $context['path'], $context['ref'])`;
`loadSubprocess($ref)` — `graph()` версии ребёнка. Этап 10.

## Доработки во flow (не блокируют, предложение)

Работаем на 1.0.x без изменений flow: всё выражается встроенными типами,
`data` и кастомными статусами. Улучшения, которые сделали бы картинку
выразительнее — **записываю, сам не правлю**. Решение автора 2026-10-26:
этап 6 делаем на flow 1.0.x, список ниже передаём во flow 1.1 после того,
как эталонный граф будет готов:

1. Встроенные типы узлов `parallel` (рамка с регионами) и `timer`/`wait`
   (часы на углу) — сейчас кастомный реестр на стороне хоста.
2. Встроенные тона для `unknown` и `compensated` (сейчас — `statusTones`
   у хоста).
3. Отрисовка граничного перехода (ребро от рамки группы/подпроцесса) —
   сейчас ребро от узла-`subprocess`, выглядит допустимо.
4. Серверная валидация в core (`FlowGraph`) уже покрывает схему — нового
   не нужно.

## Последствия

- Тесты экспорта валидируют результат **JSON-схемами flow** (своим
  минимальным валидатором, ADR-003)
  (`graph.schema.json`, `snapshot.schema.json`, копия фикстурой в
  `tests/Fixtures/flow/` с указанием версии flow) — дрейф схемы flow ловится
  тестом.
- Определения > 500 состояний на одном уровне не рисуются flow — валидатор
  определения предупреждает.
