# 运维实例

## 查看实例

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

`expects()` 只对仅依赖上下文的守卫进行求值；守卫需要读取事件的那些事件会被标记为
`conditional`。可用它来展示客户当前可以执行的操作。

列表与计数：

```php
use Dskripchenko\ProcessEngine\Store\InstanceQuery;

$engine->instances(new InstanceQuery(state: 'awaitingDocuments', definitionName: 'claim-settlement'));
$engine->instances(new InstanceQuery(hasIncidents: true));
$engine->stateCounts(ClaimSettlement::class);   // state => instances, for an overview map
$engine->openIncidents();
$engine->health();   // running, suspended, with_incidents, open_incidents, overdue_timers, expired_effect_leases
```

## 日志

每个条目都有类型、数据、操作者、原因和时间。主要类型：

| 类型 | 数据 |
|---|---|
| `started`, `adopted`, `migrated` | 版本、上下文、状态 |
| `step` | 事件及其载荷、所执行的转换（以及允许每个转换的守卫）、被守卫拒绝的候选转换、退出和进入的状态、上下文补丁（RFC 6902）、备注、命令 |
| `event.ignored`, `event.deferred`, `event.discarded` | 原因，以及期望的事件 |
| `effect.succeeded`, `effect.retry`, `effect.failed`, `effect.unknown`, `effect.reconciled`, `effect.lease_expired`, `effect.compensated`, `effect.orphaned` | 键、类、尝试次数、结果或错误 |
| `timer.fired`, `poll.result`, `child.started` | |
| `incident.opened` | 类型、消息 |
| `manual.*`, `manual.rejected` | 操作、参数、操作者、原因 |
| `completed`, `failed`, `cancelled` | 终态、输出 |

载荷按发送时的原样存储：“谁输入了什么”都在日志中。

## 人工操作

每个操作都需要一个操作者和一个**原因**。操作经由收件箱进行，因此会与普通事件一起
串行处理，并记入日志。

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

- `transitionTo` 忽略守卫。除非关闭，退出和进入动作都会执行。
- `resolveEffect` / `skipEffect` 投递常规的 `done` / `error` 事件，因此流程会像调用
  已返回一样继续。
- 对结果未知的至多一次副作用执行 `retryEffect` 时，若没有 `confirmUnsafe: true`
  会被拒绝。
- 已挂起的实例仍然接受人工操作；其事件处于等待状态。

授权由宿主应用负责：调用这些方法之前请先检查权限。

## 事故

| 类型 | 何时出现 |
|---|---|
| `effect_exhausted` | 副作用在 `onExhausted: 'incident'` 下耗尽了尝试次数 |
| `effect_failed` | 无人处理的错误（没有 `onError`），或“发出即不管”的副作用失败 |
| `effect_unknown` | 至多一次副作用的结果无法确定 |
| `compensation_failed`, `compensation_blocked` | 撤销失败 / 等待未知结果 |
| `step_error` | 守卫或动作抛出异常：事件被标记为失败，修复后调用 `redeliver()` |
| `microstep_limit` | 无事件转换陷入循环 |
| `handler_missing` | 存活版本引用的副作用类已不存在 |
| `context_too_large` | 上下文超出上限 |
| `poll_failing` | 轮询持续失败 |

有未关闭事故的实例会被标记（`hasIncidents`），且不会被归档；实例的其余部分在可能的
范围内继续工作。

## 信号

向引擎传入一个 `EventListener`，即可在提交后接收信号：
`instance.started`、`step.committed`、`event.ignored`、`effect.succeeded`、
`effect.failed`、`effect.unknown`、`incident.opened`、`incident.resolved`、
`instance.completed`、`instance.failed`、`instance.cancelled`。Laravel 桥接包会把它们
转换为 `ProcessSignal` 事件。

## 驱动运行时

| 调用 | 由谁调用 |
|---|---|
| `runInstance($id)` | 实例有事件时由 worker 调用（在 Laravel 中是一个队列任务） |
| `executeEffect($id)` | 副作用到期时由 worker 调用 |
| `tick()` | 每分钟一次：触发定时器、恢复已崩溃 worker 的副作用、为丢失的工作重新按铃、清除过期消息 |
| `archive()` | 每天一次：把结束超过 30 天的实例移入归档 |
| `drain()` | 同步模式（测试、脚本）：执行所有已按铃的工作，直到空闲 |

## 归档

没有未关闭事故和存活子流程的已结束实例，会在 `archiveAfter`（默认 30 天）之后移入
归档：每个实例一行，快照、日志、副作用和事故都经过压缩。`get()` 仍然可以返回它们
（`isArchived()`），但只读。
