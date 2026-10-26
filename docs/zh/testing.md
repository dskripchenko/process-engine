# 测试流程

`ProcessTest` 在内存引擎上同步运行流程，提供虚拟时钟、伪造副作用和故障注入。引擎自身
的测试套件就是基于这个测试框架构建的。

```php
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

it('settles a repair claim after a document request', function () {
    ProcessTest::start(ClaimSettlement::class, new ClaimContext(policyId: 'P-1'), fakes: [
        RenderApplication::class => 'doc-1',
        SubmitToInsurer::class   => 'APP-1',
    ])
        ->send(new FormSubmitted(['damage' => 'glass']))
        ->send('client.signed')
        ->assertIn('awaitingParty')
        ->publish(new DocumentsRequested(['photo']), correlate: 'APP-1')
        ->travel('10 days')
        ->assertIn('awaitingParty')
        ->assertRan(ReportMissingDocuments::class)
        ->publish(new PartyDecision(approved: true), correlate: 'APP-1')
        ->assertIn('offering', 'choosingSlot');
});
```

## 驱动

| 方法 | |
|---|---|
| `ProcessTest::start($process, $context, fakes: [...])` | 启动并运行至空闲 |
| `new ProcessTest(store:, clock:, container:)` 然后 `->begin(...)` | 使用真实存储（数据库）和你的容器 |
| `send($event, $payload, key:)`, `publish($event, correlate:, key:)` | 投递并运行至空闲 |
| `travel('3 days', step: '1 hour')` | 逐步推进时钟，按顺序触发定时器和重试 |
| `tick()`, `drain()` | 执行一次 tick；运行已到期的工作 |
| `crashAt($point, skip: 0)` | 让运行时在某个点“崩溃”（见下文） |
| `recover('10 minutes')` | 解除故障，让租约过期，由 tick 收拾残局 |
| `resolveEffect($class, succeeded:, result:)` | 人工处理一个副作用 |
| `view()`, `engine`, `clock`, `signals` | 查看内部 |

## 伪造（fake）

```php
->fake(ChargeCard::class, returns: 'tx-1')
->fake(ChargeCard::class, throws: new RuntimeException('timeout'))             // ambiguous
->fake(ChargeCard::class, sequence: [new EffectNotExecuted('refused'), 'tx-2']) // answers per call
->fake(ChargeCard::class, using: fn (Effect $fx) => 'tx-'.$fx->index)
->fake(ChargeCard::class, returns: 'tx-1', reconcile: Outcome::succeeded('tx-1'))
->fake(ChargeCard::class, returns: 'tx-1', crash: 'after-call')  // dies after the call, before the record
->fake(BookService::class, compensateThrows: new RuntimeException('down'))
```

伪造对象会记录调用（`fakeOf($class)->calls`、`->reconciles`、`->compensations`）。
语义仍然来自真实类上的属性。

## 断言

`assertIn(...$states)`、`assertNotIn()`、`assertFinal($state)`、
`assertStatus($status)`、`assertContext('path.to.value', $expected)`、
`assertCalls($class, $n)`（伪造对象的调用次数）、`assertRan($class, $n)`
（成功的副作用）、`assertEffect($class, $status)`、
`assertIncident($type)`、`assertNoIncidents()`。

## 崩溃

可以让运行时崩溃的故障点：

| 故障点 | 情形 |
|---|---|
| `runner.before_commit` | 单步已计算，尚未保存任何内容 |
| `runner.after_commit` | 单步已保存，门铃未按响 |
| `effect.after_claim` | 副作用已标记为运行中，处理器未被调用 |
| `effect.after_call` | 处理器已返回，结果未保存 |
| `effect.after_record` | 结果已保存 |
| `reconcile.after_call` | 对账已返回，未保存 |
| `ticker.after_fire` | 定时器已标记为触发，其事件未入队 |

崩溃会回滚未完成的事务，并像 worker 被杀死那样保留租约。在 `recover()` 之后，检查你
关心的不变量：

```php
it('never charges twice when the worker dies mid-call', function () {
    $t = (new ProcessTest)
        ->fake(ChargeCard::class, returns: 'tx-1', crash: 'after-call', reconcile: Outcome::succeeded('tx-1'))
        ->begin(OrderFlow::class)
        ->send('submit', ['items' => ['sku-1']]);

    $t->recover()
        ->assertCalls(ChargeCard::class, 1)
        ->assertContext('paymentId', 'tx-1')
        ->assertIn('shipping');
});
```

引擎自身的测试套件会在两个场景中以多种命中次数运行每个故障点，并检查：没有遗留的
待处理事件、每个至多一次键最多调用一次、副作用键唯一、每个外部事件对应一步，以及
依次应用日志中的上下文补丁能得到已存储的上下文。

## 竞态

同一存储上、使用不同 worker id 的两个引擎可以模拟两个 worker：

```php
$store = new InMemoryStore;
$clock = new VirtualClock;
$a = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'A');
$b = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'B');
```

Laravel 桥接包的测试套件还增加了针对 SQLite、MySQL 和 PostgreSQL 的真实进程
（`pcntl_fork`）测试，以及在调用过程中被 SIGKILL 杀死的 worker。
