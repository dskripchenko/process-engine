# 版本与迁移

## 版本永不改变

每个流程类都声明 `VERSION`。实例启动时，该版本的规范形式连同其哈希一起被存储。
用不同的图注册相同的版本会抛出 `DefinitionVersionConflict`：修改了图，就要提升版本。

```php
final class ClaimSettlement extends Process
{
    public const VERSION = 2;   // was 1
    // …
}
```

（在开发环境中，可以允许重新定义尚无实例使用的版本：
`new Engine(..., allowRedefineUnused: true)`。）

## 旧实例在其版本上运行结束

实例绑定到它启动时所存储的版本，引擎执行的是**已存储的形式**，而不是类。你可以为
版本 2 重写 `define()`；版本 1 的实例仍在版本 1 的图上运行。

新实例以类所声明的版本启动。按名称调用 `start('claim-settlement')` 会启动最新的
已存储版本；`start(..., version: 1)` 启动指定版本。

只要旧版本还有存活实例，代码中就必须保留它们引用的**名称**：用作守卫和动作的流程类
方法、副作用类、子流程类。不兼容的行为变更应使用新名称（`ChargeV2`），并保留旧名称，
直到其实例全部结束。

`$engine->doctor()`（artisan `process-engine:doctor`）会列出拥有存活实例、但其方法或
类已缺失的已存储版本。运行时缺少处理器会创建事故，而不会使实例失败。

## 迁移实例

迁移是显式的、逐个实例进行的、记入日志的，并且会先进行检查。

```php
use Dskripchenko\ProcessEngine\Versioning\Migration;

$migration = Migration::from(ClaimSettlement::class, 1)->to(2)
    ->mapState('waiting', 'awaitingParty')        // renamed states
    ->dropState('legacyFax')                      // instances standing here are not migrated
    ->context(fn (array $c) => [...$c, 'requests' => count($c['docRequests'] ?? [])])
    ->when(fn (InstanceView $i) => $i->businessKey() !== null);

$report = $engine->migrate($migration, Actor::operator('ann'), 'introduce the fraud check');   // dry run
$report->eligible;   // instance ids
$report->skipped;    // id => why not

$engine->migrate($migration, Actor::operator('ann'), 'introduce the fraud check', dryRun: false);
```

只有当实例处于静止状态时才会被迁移：

- 它运行的是源版本且尚未结束；
- 没有未关闭的事故；
- 没有正在运行或结果未知的副作用；
- 没有正在运行的被调用子流程；
- 每个活动状态在目标版本中都有对应状态（已映射或 id 相同）。

在实例租约下应用迁移：

1. 绑定到旧激活的一切都会结束：待处理的副作用、定时器、轮询、订阅；
2. 转换上下文；
3. 实例进入新版本中映射后的状态，**不执行进入动作**——这是一次移动，而不是新的进入；
   这些状态的定时器、轮询、订阅和调用会启动（定时器从迁移时刻开始计时）；
4. 日志中写入一条 `migrated` 条目，包含状态映射、操作者和原因。

迁移是由运维人员执行的代码，因此这里可以使用闭包。用 `$engine->registerMigration()`
注册后，之后可以按名称应用它们
（`process-engine:migrate claim-settlement@1->2 --apply --reason=…`）。

## 从外部引入流程

`adopt()` 直接在给定状态中创建实例——适用于曾在遗留代码中运行的流程，或在其他地方
创建的记录：

```php
$engine->adopt(ClaimCase::class, in: ['awaitingDocuments'], context: $context,
    key: "claim:{$legacyId}", by: Actor::operator('migration'), reason: 'move from the old system');
```

进入动作不会执行；这些状态的定时器、轮询、订阅和调用会启动。键使批量导入可以安全地
重复运行。
