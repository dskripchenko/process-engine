# 事件、定时器与轮询

## 向实例发送事件

```php
$engine->send($instanceId, new FormSubmitted($form), key: $requestId, by: Actor::user($userId));
$engine->send($instanceId, 'client.signed', ['at' => '2026-10-26'], key: 'sig-881');
```

- 事件可以是一个对象（其公共属性成为载荷；名称为其类名，或由 `#[Event('name')]`
  指定），也可以是一个名称加一个数组。
- **键使投递具有幂等性。** 用同一个键发送两次，第二次调用返回 `Receipt::DUPLICATE`。
  不传键时会使用随机键，这样起不到任何保护作用——对任何可能被重试的操作（HTTP 请求、
  webhook、队列消息）都应传入键。
- `by:` 在日志中记录操作者。
- 当前状态不接受的事件会被**忽略**，日志会说明原因以及期望的事件。异步参与方不应
  因为时序问题而丢失应答，因此这对发送方来说不算错误。

`sendStrict()` 会先进行检查，并返回带有期望事件的 `Receipt::REJECTED`，适用于需要
提示“表单已经提交过”的界面。

### 校验事件

实现 `SelfValidating` 的事件类会在入队前自我校验；发送方会收到异常，流程永远不会
看到错误数据：

```php
final class FormSubmitted implements SelfValidating
{
    public function __construct(public array $form) {}

    public function validate(): void
    {
        if (empty($this->form['damage'])) {
            throw new InvalidArgumentException('Describe the damage');
        }
    }
}
```

`$engine->addValidator(fn (Event $e) => ...)` 为每个事件添加一项检查。

## 按关联键发布

当对方只知道自己的引用（例如申请编号）时，状态以该键进行订阅：

```php
->state('awaitingParty', fn (Builder $s) => $s
    ->correlate([PartyDecision::class, DocumentsRequested::class], by: 'externalId')
    ->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...)))
```

```php
$engine->publish(new PartyDecision(approved: true), correlate: 'APP-123', key: $webhookMessageId);
```

- 订阅在进入状态时根据上下文属性 `by:` 创建，在离开时移除。应在键已知的状态中
  订阅。
- 尚无人等待的已发布事件会被**缓冲**（默认 7 天），一旦有实例订阅即投递：在流程
  开始等待之前到达的应答不会丢失。
- 重复项（相同的事件、键和关联键）会被丢弃。

## 任务令牌（task token）

一种发往某状态当前激活的回调：

```php
$token = $engine->taskToken($instanceId, 'awaitingSignature');   // put it into a callback URL
// later, in the callback handler:
$engine->complete($token, 'signature.completed', $payload);
```

令牌经过签名（使用引擎密钥的 HMAC）。伪造的令牌会抛出异常；已结束激活的令牌返回
`Receipt::REJECTED`。

## 延迟处理（defer）

```php
->state('submitting', fn (Builder $s) => $s->defer(PartyDecision::class))
```

被延迟的事件会留在收件箱中，直到接受它的状态变为活动（默认 30 天），而不是被忽略。

## 定时器

```php
->after('10 days', to: 'reminded')                    // from entering the state
->after('PT2H', run: RemindClient::class)              // ISO 8601; no target: stay
->after($this->slaFor(...), to: 'escalated')           // a method returning a duration or a moment
->after(businessDays(5), to: 'late')                   // through a BusinessCalendar
->at('deadline', to: 'expired')                        // a context property with an ISO 8601 date
```

- 定时器属于其状态的某一次激活：离开状态会取消它；重新进入会启动一个新的定时器。
  在离开状态之后才触发的定时器会被丢弃。
- 触发是幂等的（定时器键），因此重复的 tick 不会产生任何效果。
- 时长格式：`14 days`、`1 day, 2 hours`、`10s`、`2h`、`P14D`、`PT10M`。
- 工作日使用 `BusinessCalendar`（默认：周一至周五）。引擎不了解节假日；请把你的
  日历传给引擎。
- 精度取决于 tick：每分钟运行一次 `tick()`（调度器），或使用 worker 循环获得秒级
  精度。定时器用于业务截止时间，而非毫秒级计时。

<a id="polling"></a>
## 轮询

适用于无法回调的参与方：

```php
->state('waitingForSignature', fn (Builder $s) => $s
    ->poll(CheckSignature::class, every: '1 minute')
    ->on(SignatureCompleted::class, to: 'signed')
    ->on('signature.rejected', to: 'refused')
    ->after('24 hours', to: 'expired'))
```

```php
#[Idempotent]
final class CheckSignature
{
    public function __invoke(array $context): SignatureCompleted|string|null
    {
        $state = $this->service->status($context['requestId']);

        return match ($state) {
            'complete' => new SignatureCompleted(),
            'rejected' => 'signature.rejected',
            default    => null,          // nothing new: no event, no journal entry
        };
    }
}
```

- 处理器必须是 `#[Idempotent]`。
- `null` 表示“暂无结果”：没有事件，**也没有日志条目**，只更新计数器。每分钟轮询
  一次、持续一天，不会给日志带来任何开销。
- 事件（对象或名称）或事件列表会被投递给实例。
- `every:` 可以是流程的一个方法，在每次运行前重新求值：客户在线时每 5 分钟轮询一次，
  否则每小时一次。
- 失败遵循处理器的重试策略；重试耗尽时会创建事故，轮询以最大间隔继续进行。
- 事件可以触发一次额外运行：`->on('client.opened-page', run: CheckSignature::class)`。

### 扇出

轮询（或任何副作用）可以发往其他实例：

```php
public function __invoke(array $context): array
{
    return array_map(
        fn (array $claim) => Publish::to($claim['id'], new ClaimStatusChanged($claim['status'])),
        $this->insurer->clientClaims($context['clientId']),
    );
}
```

`Publish::to($correlationKey, $event)` 经由订阅和缓冲区投递；
`Send::to($instanceId, $event)` 直接投递到收件箱。为某个客户的所有理赔发起一次请求，
远比为每个理赔分别轮询便宜。
