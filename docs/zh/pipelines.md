# 数据流水线：批次与可关闭的阶段

业务流程以人的节奏推进：一次审批要等好几天才能签字。数据流水线则不同：一个阶段要处理
**成千上万乃至一百万个元素**（待补全的对象、待加载的地区、待检查的代理），每个元素各自
结束，外部服务限制调用频率，而当服务宕机或缺少密钥时，阶段会被关闭。有两种构造来应对
这些情况：**批次**和**可关闭的状态**。

## 批次

```php
use Dskripchenko\ProcessEngine\Batch\BatchHandler;
use Dskripchenko\ProcessEngine\Batch\BatchResult;
use Dskripchenko\ProcessEngine\Batch\ItemOutcome;
use Dskripchenko\ProcessEngine\Batch\ItemSource;

$p->state('dadata', fn (Builder $s) => $s
    ->batch(DadataLookup::class, items: ObjectsToEnrich::class, chunk: 50, concurrency: 2,
        itemAttempts: 3, into: 'counters.dadata', onDone: 'nspd', onError: ['fatal' => 'nspd']));
```

元素的键**从数据源逐页获取**，从不经过上下文，因此包含一百万个元素的批次也不会让实例
膨胀：

```php
final class ObjectsToEnrich implements ItemSource
{
    public function __construct(private readonly Objects $objects) {}

    /** The next page strictly after $after, in a stable order; [] — no more. */
    public function keys(array $context, ?string $after, int $limit): array
    {
        return $this->objects->idsOfGroup($context['group'], after: (int) $after, limit: $limit);
    }

    /** Optional: the total, for progress. */
    public function count(array $context): ?int
    {
        return $this->objects->countOfGroup($context['group']);
    }
}
```

**处理器**接收一块键，并给出每个键的结果：

```php
#[Idempotent(retry: new Retry(attempts: 10, initialInterval: '1 minute', maxInterval: '1 hour'))]
final class DadataLookup implements BatchHandler
{
    public function handle(array $context, array $keys, Effect $fx): BatchResult
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = match ($this->lookup($key)) {
                'found' => ItemOutcome::done(),
                'none' => ItemOutcome::notFound(),
                default => ItemOutcome::failed('timeout'),
            };
        }

        return BatchResult::of($out);
    }
}
```

| 参数 | 默认值 | |
|---|---|---|
| `chunk` | 100 | 每次调用处理器的键数 |
| `concurrency` | 1 | 同时执行的块数 |
| `itemAttempts` | 3 | 单个元素停留在 `failed` 之前的尝试次数 |
| `retry:` | 处理器的策略 | 块如何重试 |
| `into:` | — | 计数写入上下文的位置 |

处理器必须是 `#[Idempotent]`：重试的块会重复处理其中的元素。

### 结果

| 处理器…… | 结果 |
|---|---|
| 返回各元素的结果 | `done`、`not_found`、`skipped`、`deferred` 是最终结果；`failed` 会在之后的块中再次尝试，直到达到 `itemAttempts`，之后保持 `failed`。结果中缺失的键按失败计 |
| 抛出 `EffectNotExecuted`（配额、暂停、连接被拒绝） | 该块按其策略以递增的间隔重试：阶段**等待**，而不是失败 |
| 抛出其他异常 | 同上：处理器是幂等的 |
| 块的重试次数耗尽 | 块中每个元素失去一次尝试机会；批次继续 |
| 抛出 `EffectFailed($code)`（没有密钥、余额不足、被封禁） | **批次停止**：尚未执行的元素变为 `deferred`，流程收到带错误码的 `error.invoke`——用 `onError:` 为其指定路由 |

当数据源读取完毕且每个元素都有结果时，流程收到带计数的 `done.invoke`：
`{done, not_found, failed, deferred, skipped, total}`。

### 运行

元素保存在存储中（`BatchStore`），块是普通的副作用记录：租约、尝试超时以及 tick 的
恢复机制与任何副作用一样生效。worker 无论在哪里崩溃都不会丢失任何东西：每个元素最终
都有结果，`done` 只投递一次。已挂起的实例不会启动新的块；离开该状态会取消批次，并把
仍在等待的元素延后。

在 PostgreSQL 上，引擎自身的开销约为每秒 10 000 个元素，从第一个元素到第一百万个元素
保持平稳（块大小 500，并发 8，处理器只做应答）。

### 操作

```php
$engine->batches($instanceId);                       // BatchRecord: status, counters, total
$engine->batchItems($batchId, 'failed', limit: 100);  // elements with their reason and attempts
$engine->retryBatchItems($instanceId, $batchId, $by, 'the timeout is fixed', ['failed', 'deferred']);
$engine->skipBatch($instanceId, $batchId, $by, 'not needed today');
```

这两个操作都作用于正在运行的批次，并记入日志。已结束的批次可以通过对其元素重新运行
一次流程来重试，或者把实例移回该状态（从头开始一个新批次）。

流程图在正在运行的批次的节点上显示它：一个 `finished / total` 徽标和一行计数。

## 可关闭的状态

```php
$p->state('dadata', fn (Builder $s) => $s
    ->switchable('enrichment.dadata', skipTo: 'nspd', offOn: ['fatal'], label: 'DaData')
    ->batch(...));
```

当开关关闭时，可关闭的状态会被**跳过**，直接转到 `skipTo`。它不调用任何东西，跳过会
记录在上下文的 `skipped` 列表中（`{state, switch, reason}`），因此被跳过的阶段绝不会
看起来像已通过的阶段。开关在以下情况下处于关闭状态：

- **全局**——`$engine->switchOff('enrichment.dadata', $by, 'no key yet')`，直到
  `switchOn()`；停留在此类状态中的实例会立即离开（其批次被取消）；
- **仅本次运行**——开关列在启动上下文中：`['disabled' => ['enrichment.dadata']]`；
- **由引擎关闭**——该状态的一次调用以 `offOn` 中的错误码失败：开关被关闭，以错误消息
  作为原因、系统作为操作者，信号 `switch.changed` 让应用可以通知相关人员。

没人动过的开关是**开启**的（与标志不同）。开关存放在 `SwitchProvider` 中：内存中的
`StaticFlags`（引擎的默认实现）；Laravel 桥接包把它们保存在一张表里。
`$engine->switchState($name)` 告诉你是谁、在何时、因何切换了它。必须始终执行的阶段，
不设为可关闭即可。

使用开关提供者构建的 `FlowExporter` 会用 `enabled` 和 `data.switch` 标记可关闭的节点，
因此流程图能显示哪些阶段已关闭。
