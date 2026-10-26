# 结构：嵌套、并行区域、历史状态、子流程

有两种结构，代价各不相同：实例内部的结构（复合状态和并行状态）和跨实例的结构
（子流程）。

## 复合状态

```php
$p->state('application', fn (Builder $s) => $s
    ->after('14 days', to: 'abandoned')              // applies to every state inside
    ->on('client.cancelled', to: 'cancelled')        // so does this
    ->state('filling', fn (Builder $s) => $s->on(FormSubmitted::class, to: 'signing'))
    ->state('signing', fn (Builder $s) => $s->on('client.signed', to: 'signed'))
    ->final('signed')
    ->onDone(to: 'review'));
```

- 子状态共享上下文和日志。
- 在复合状态上声明的转换，在其内部任意状态下都有效：`application` 上的定时器就是
  整个阶段的截止时间。
- 到达终态子状态时会触发 `done.state.<id>`；`onDone()` 对其作出响应。

## 并行状态

```php
$p->parallel('agreement', fn (Builder $s) => $s
    ->region('compensation', fn (Builder $r) => $r
        ->state('offering', fn (Builder $s) => $s->on('client.accepted', to: 'agreed'))
        ->final('agreed', output: 'compensation'))
    ->region('service', fn (Builder $r) => $r
        ->state('choosingSlot', fn (Builder $s) => $s->on('client.slot-chosen', to: 'booked'))
        ->final('booked'))
    ->onDone(to: 'route'), join: 'all');
```

- `join: 'all'`（默认）：所有区域都到达终态时完成；`done` 事件携带每个区域的输出。
- `join: 'any'`：第一个区域结束时完成；其他区域被退出，其待处理的副作用和子流程
  被取消。
- 区域在逻辑上并行、在物理上顺序执行（每个实例一个租约）；它们的副作用则真正并行
  运行。

## 历史状态

```php
$p->state('work', fn (Builder $s) => $s
    ->on('pause', to: 'paused')
    ->state('step1', /* … */)
    ->state('step2', /* … */)
    ->history('resume')                 // shallow: the last active child
    ->history('resumeDeep', deep: true)); // deep: the exact nested states
$p->state('paused', fn (Builder $s) => $s->on('continue', to: 'resume'));
```

进入历史状态会恢复父状态上次退出时的配置（首次进入时为父状态的初始状态，或
`default:`）。

## 子流程

一个状态可以把另一个流程作为独立实例运行：

```php
->invoke(DocumentRequest::class,
    input: $this->requestInput(...),
    onDone: ['signed' => 'review', 'refused' => 'refused', 'expired' => 'refused'],
    onError: 'failed')
```

- 子流程拥有自己的版本（启动时确定）、上下文、日志和租约。它只能看到自己的
  `input`；父流程只能看到它的 `output`（`final('signed', output: 'files')`）。
- 使用映射形式的 `onDone` 按子流程的终态进行路由；构建器会检查子流程的每个终态都
  已被路由。
- 如果父流程离开该状态，子流程会被取消。失败或被取消的子流程会发送
  `error.invoke.<id>`，错误码为 `child_failed` / `child_cancelled`。

### 每个元素一个子流程

```php
->invoke(SignDocument::class, forEach: 'documents', into: 'signatures', onDone: 'done')
```

N 个子流程，结果按列表顺序收集，全部完成时即完成。

### 每个事件一个子流程：`spawn`

```php
$p->on(NotificationReceived::class, spawn: ClaimRequest::class, input: $this->requestInput(...), key: 'messageId');
```

- 每个事件启动一个子流程，父流程不发生转换。
- `key`（事件字段）用于去重：相同的值绝不会启动第二个子流程。
- 子流程的生命周期与声明 `spawn` 的作用域（某个状态或整个流程）相同；
  `onScopeExit: 'keep'` 允许它在作用域结束后继续存活。
- 其完成以 `done.spawn.<id>` 的形式到达；是否响应是可选的。
  `StepContext::spawned()` 统计存活的子流程数量，供守卫使用。

## 如何选择

| 需求 | 使用 |
|---|---|
| 同一流程的多个阶段，共享上下文，“从阶段内任意位置” | 复合状态 |
| 同时运行并最终汇合的分支 | 并行状态 |
| 拥有自己版本和生命周期的可复用部分 | 子流程 |
| 由数据生成的 N 个相似分支 | 子流程的 `invoke(..., forEach:)` |
| 由数据生成的 N 个相似外部调用 | 副作用的 `invoke(..., forEach:)` |
| 以事件形式到达、数量未知的子流程 | `spawn` |
