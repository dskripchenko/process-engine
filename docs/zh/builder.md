# 定义流程

## 流程类

```php
use Dskripchenko\ProcessEngine\Attributes\ProcessName;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

#[ProcessName('claim-settlement')]        // optional; default: kebab-case class name
final class ClaimSettlement extends Process
{
    public const VERSION = 1;               // required; bump it whenever the graph changes
    public const STATES = ClaimState::class; // optional: an enum of state ids, checked against the graph

    public function define(Builder $p): void { /* the graph */ }

    public function projections(): array { return []; } // optional, see the Laravel bridge
}
```

该类**在没有构造函数参数的情况下**实例化。因此守卫和动作无法访问服务，也无法进行
I/O；任何外部操作都是副作用。正是这一点保证了单步是纯函数。

## 构建器应用的规则

- 状态 id 在流程内唯一；转换目标用 id 表示。
- 第一个声明的子状态是初始状态（可用 `initial()` 覆盖）。
- 守卫和动作是**流程的方法**，写作 `$this->method(...)`，或者是声明式谓词和动作。
  匿名闭包会被拒绝：它们无法被存储或版本化。
- 副作用是带有 `#[Idempotent]` 或 `#[AtMostOnce]` 的类；没有重试语义的类会被拒绝。
- 状态 id 可以是字符串或 backed enum 的枚举项（`ClaimState::Review`）。

`define()` 在首次使用时被编译为规范数组。错误（未知目标、缺失语义、未知方法、未路由
的子流程终态）会使构建失败，并列出所有问题；警告会指出不可达的状态以及可能卡住的
选择。

## 状态

```php
$p->state('review', fn (Builder $s) => $s /* … */);   // atomic, or compound with children
$p->parallel('agreement', fn (Builder $s) => $s
    ->region('money', fn (Builder $r) => $r /* … */)
    ->region('service', fn (Builder $r) => $r /* … */), join: 'all'); // or 'any'
$p->final('closed', output: 'payoutId');              // a context property or a method
$p->choice('route')->when($this->isMoney(...), 'payout')->otherwise('finalizing');
$p->history('resume', deep: true);                    // inside a compound state
```

关于嵌套、并行区域、历史状态和子流程，参见[结构](structure.md)。

## 转换

```php
->on('client.signed', to: 'review')
->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...))
->on(PartyDecision::class, to: 'rejected', run: $this->storeReason(...))   // first matching wins
->on('client.edit', run: $this->touch(...))                                 // no target: internal
->always(to: 'next', when: $this->ready(...))                               // eventless
->after('10 days', to: 'reminded')                                          // timer, see events
->onDone(to: 'agreement')                                                   // the compound reached its final
```

`on()` 的参数：

| 参数 | 含义 |
|---|---|
| `to:` | 目标状态；不指定时该转换为内部转换（不退出/不进入） |
| `when:` | 守卫：返回 `bool` 的方法，或一个谓词 |
| `run:` | 动作：方法、副作用类、`assign()`/`raise()`/`note()`，或它们的列表 |
| `label:` | 供界面使用的标签（`expects()`、按钮） |
| `from:` | 仅用于流程级：源状态，或用 `'*'` 表示任意未结束状态 |
| `match:` + `targets:` + `otherwise:` | 基于事件字段的路由表 |
| `spawn:` + `input:` + `key:` | 每个事件启动一个子流程（参见结构） |

### 路由表

```php
->on(ClaimStatusChanged::class, match: 'status', targets: [
    'upload_documents' => 'awaitingDocuments',
    'payment'          => 'paying',
    'done'             => 'settled',
], otherwise: 'reviewing')

$p->choice('kind')->match('scenario', ['documents' => 'documents', 'inspection' => 'inspection'], otherwise: 'info');
```

每个值都会变成一个普通的带守卫的转换，因此图仍然是显式且可绘制的。

### 从任意状态

```php
$p->on('client.cancelled', to: 'cancelling', from: ['application', 'review']);
$p->on('client.cancelled', to: 'cancelled', from: '*');
```

## 以方法实现守卫和动作

参数**按类型和名称**注入：

| 参数 | 接收内容 |
|---|---|
| 上下文类（`ClaimContext $c`） | 上下文对象（修改会被保存） |
| `array $context` / `array &$context` | 上下文数组（按引用传递才能修改） |
| 事件类（`PartyDecision $e`） | 由载荷重建的事件 |
| `array $event` | 事件载荷 |
| `StepContext $step` | 该步的时间、实例 id、`isIn()`、`spawned()` |
| 其他 | 附加参数（`forEach` 的元素等） |

```php
private function isApproved(PartyDecision $e): bool { return $e->approved; }
private function countRequest(ClaimContext $c): void { $c->documentRequests++; }
private function sla(array $context): string { return $context['vip'] ? '1 hour' : '1 day'; }
```

## 上下文

可以是数组，也可以是带公共属性的对象（构造函数属性提升同样可用）。支持的类型：
标量、数组、backed enum、`DateTimeImmutable`、嵌套对象；对象列表需要
`#[ListOf(Item::class)]`。大型数据（文件、文档）应存放在外部，只保存引用。默认
大小上限为 256 KB 的 JSON。

副作用从不直接修改上下文：它们返回一个结果，通过 `into:` 保存，或由 `onDone` 方法
使用。这样所有上下文变更都发生在单步事务之内。

## 声明式谓词和动作

适用于从 JSON 加载的定义，或简短的守卫：

```php
use function Dskripchenko\ProcessEngine\{eq, gt, in, exists, all, any, not, flag, assign, fromEvent, inc, raise, note};

->on('pay', to: 'paid', when: all(gt('$.event.amount', 0), not(exists('blocked'))))
->on('edit', run: assign('form', fromEvent('form')))
->on('retry', run: [assign('attempts', inc(1)), note('retried by the client')])
->on('fast', to: 'express', when: flag('express-lane'))   // a host feature switch
```

不带 `$.` 的路径表示上下文属性（`'amount'` 即 `$.context.amount`）。
运算符：`eq ne gt gte lt lte in exists empty all any not flag`。

## 状态的活动

```php
->entry(NotifyClient::class, $this->stamp(...))   // on entry: effects and methods
->exit(note('left review'))
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing')   // lives while the state is active
->poll(CheckSignature::class, every: '1 minute')                       // see events
->correlate([PartyDecision::class], by: 'externalId')                  // see events
->defer(PartyDecision::class)                                          // keep until a state accepts it
->compensate(onDone: 'cancelled')                                      // see effects
->meta(label: 'Under review', description: 'The insurer checks the claim')
```

### `invoke()` 选项

| 选项 | 含义 |
|---|---|
| `into:` | 接收结果的上下文属性 |
| `input:` | 构建输入的方法（`Effect::$input`，或子流程的上下文） |
| `forEach:` | 一个上下文列表：每个元素调用一次，结果按顺序收集 |
| `concurrency:` | `forEach` 同时进行的调用数上限 |
| `onDone:` | 目标；对子流程而言，是“终态 → 目标”的映射 |
| `onError:` | 目标，或“错误码 → 目标”的映射（其余情况用 `'*'`） |
| `onUnknown:` | 至多一次（at-most-once）的结果未知时的去向（默认：等待） |
| `retry:` | 覆盖副作用默认策略的 `Retry` 策略 |

## 副作用链

```php
use function Dskripchenko\ProcessEngine\step;

$p->steps('registration', [
    step(AddParticipant::class, forEach: 'participants', into: 'participantIds'),
    step(CreateClaims::class, into: 'claims'),
    step(AttachDocument::class, forEach: 'documents', concurrency: 5),
], onDone: 'settlement', onError: 'registrationFailed');
```

每一步都会成为一个独立的状态（在日志和 flow 中可见），在其副作用完成后进入下一步。

## 界面标签

状态上的 `meta(label:)` 和转换上的 `label:` 不会被引擎读取。`InstanceView::label()`
返回最内层带标签的活动状态的标签；`expects()` 返回当前可接受的事件及其标签——即
客户端可以点击的按钮。
