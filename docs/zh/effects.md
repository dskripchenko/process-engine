# 副作用与重试语义

**副作用（effect）**是任何与外部世界交互的操作：API 调用、支付、电子邮件、生成的
文档。副作用从不在单步内部执行：单步把它们存入 outbox，在该步提交之后、在任何事务
或锁之外执行。其结果以事件的形式返回给流程。

## 语义是必需的

```php
use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
```

| 属性 | 保证 | 结果存疑时 |
|---|---|---|
| `#[Idempotent]` | 至少执行一次 | 按其策略重试 |
| `#[AtMostOnce]` | 每个键至多执行一次 | 变为 `unknown`：不自动重试 |

没有默认值。缺少这两者之一的副作用类会在流程构建时被拒绝——作者必须决定重复调用
是否安全。重复发送一封电子邮件只是有些烦人；重复扣款则不可接受。

## 编写副作用

```php
#[AtMostOnce]
final class SubmitToInsurer implements Reconcilable
{
    public function __construct(private readonly InsurerApi $api) {}  // any services

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        try {
            return $this->api->submit($claim->application, idempotencyKey: $fx->key)->id;
        } catch (ConnectionRefused $e) {
            throw new EffectNotExecuted($e);   // never reached the other side
        }
    }

    public function reconcile(ClaimContext $claim, Effect $fx): Outcome
    {
        $found = $this->api->findByIdempotencyKey($fx->key);

        return $found ? Outcome::succeeded($found->id) : Outcome::stillUnknown();
    }
}
```

参数按类型注入：上下文（对象或 `array $context`）、`Effect $fx`，以及按名称注入的
`$item` / `$input` / `$result`。`Effect` 包含：

| 字段 | |
|---|---|
| `key` | 幂等键：同一次调用的多次重试中保持不变，状态的新激活则使用新键 |
| `instanceId`, `attempt`, `now` | |
| `item`, `index` | `forEach` 的元素 |
| `input` | `invoke(..., input:)` 构建的内容 |

**只要对方支持幂等键，就把 `$fx->key` 传给对方。** 正是它使对账成为可能。

## 结果如何分类

| 处理器…… | 结果 |
|---|---|
| 返回一个值 | `succeeded`；流程收到带 `output` 的 `done.invoke.<id>` |
| 抛出 `EffectNotExecuted` | 调用确定没有发生：在尝试次数内重试（任意语义） |
| 抛出 `EffectFailed($code)` | 调用已发生但被拒绝：`failed`，发送带错误码的 `error.invoke.<id>`，不重试 |
| 抛出其他异常、超时，或其 worker 崩溃 | 结果不明确：`#[Idempotent]` 重试，`#[AtMostOnce]` 变为 `unknown` |

HTTP 客户端的经验法则：发送前连接被拒绝或 DNS 失败 → `EffectNotExecuted`；4xx 业务
响应 → `EffectFailed`；发送后读取超时或 5xx → 让异常继续抛出（结果不明确）。

## 精确理解“至多一次”

1. 认领副作用：`running`，attempt + 1，获取租约。这个“已开始”标记在调用**之前**
   提交。
2. 处理器执行。
3. 结果与发给流程的事件一起提交。

如果 worker 在 1 与 3 之间崩溃，租约过期，tick 会将该副作用标记为 `unknown`——绝不会
是 `pending`。流程收到 `unknown.invoke.<id>`。

随后：
- 若实现了 `Reconcilable`，`reconcile()` 按其自身策略执行（默认 10 次尝试，间隔从
  1 分钟到 6 小时）。`succeeded`/`failed` 会像调用已返回一样关闭该副作用；
  `notExecuted` 允许再进行一次真实调用；`stillUnknown` 稍后再试，尝试次数耗尽时
  创建一个事故；
- 若未实现，则立即创建事故。

流程在该状态中等待（如果声明了 `onUnknown:`，则转到该处）。人工在核实对方情况后
通过 `resolveEffect()` 处理，或通过 `retryEffect(..., confirmUnsafe: true)` 重新执行，
并在日志中承担责任。

## 重试策略

```php
use Dskripchenko\ProcessEngine\Effect\Retry;

#[Idempotent(
    retry: new Retry(attempts: 5, initialInterval: '10 seconds', backoff: 2.0, maxInterval: '1 hour',
                     jitter: true, attemptTimeout: '2 minutes', onExhausted: 'error'),
    nonRetryable: [ValidationError::class],
)]
```

| 字段 | 默认值（幂等 / 至多一次） |
|---|---|
| `attempts` | 5 / 3（对至多一次的副作用，只有 `EffectNotExecuted` 会触发重试） |
| `initialInterval`, `backoff`, `maxInterval`, `jitter` | 10 秒、×2、1 小时、开启 |
| `attemptTimeout` | 2 分钟：单次尝试的租约；更长的调用被视为已失效 |
| `onExhausted` | `error`：发送错误码为 `retries_exhausted` 的 `error.invoke.<id>`（未处理 → 事故）；`incident`：创建事故，流程等待；`fail`：实例失败 |

`invoke(..., retry: new Retry(...))` 在图中的某一处覆盖策略；语义仍以类上声明的为准。

## 图中的结果与错误

```php
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing', onError: [
    'bad_template' => 'manualCheck',
    '*'            => 'failed',
])
```

副作用也可以返回 `Publish::to($correlationKey, $event)` 或
`Send::to($instanceId, $event)`（或它们的列表），向其他实例投递事件——参见
[事件](events-and-timers.md)。

`entry()`、`exit()` 和 `run:` 中的副作用是“发出即不管”的：它们的结果不会推动流程，
但遵循相同的语义，最终失败的副作用会创建一个事故。

## 补偿（saga）

可以撤销的副作用实现 `Compensable`：

```php
#[AtMostOnce]
final class PayOut implements Compensable, Reconcilable
{
    public function __invoke(ClaimContext $claim, Effect $fx): string { /* transfer */ }
    public function reconcile(Effect $fx): Outcome { /* look up by $fx->key */ }
    public function compensate(mixed $result): void { /* refund the transfer $result */ }
}
```

```php
$p->state('cancelling', fn (Builder $s) => $s->compensate(onDone: 'cancelled'));
// or for one stage:
$p->state('undoBooking', fn (Builder $s) => $s->compensate(onDone: 'choosingSlot', scope: 'agreement'));
```

- 进入时，已完成的可补偿副作用（整个流程的，或指定状态的）会**按完成的逆序逐个**
  撤销。
- `compensate()` 接收原始结果；它会被重试（默认 20 次），之后创建事故。
- 作用域内处于 `unknown` 的副作用会**阻塞**补偿（并创建事故）：撤销一件可能根本
  没有发生的事是错误的。
- `Engine::cancel($id, ..., compensate: true)` 先补偿整个流程，然后取消它。

## 轮询

轮询是绑定到某个状态的周期性幂等副作用；参见
[事件](events-and-timers.md#polling)。
