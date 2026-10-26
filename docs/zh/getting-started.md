# 快速上手

## 安装

```bash
composer require dskripchenko/process-engine
```

需要 PHP 8.2 或更高版本，无其他依赖。如果使用 Laravel，请改为安装桥接包：
`composer require dskripchenko/laravel-process-engine`（它会引入核心包，并提供
数据库存储、队列 worker 和 artisan 命令）。

## 1. 描述流程

流程是一个类，包含版本号和用于构建图的 `define()` 方法。守卫（guard）和上下文
更新是同一个类中的小方法，以 `$this->method(...)` 的形式引用。

```php
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

final class OrderFlow extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('new', fn (Builder $s) => $s
            ->on('submit', to: 'paying', run: $this->keepItems(...)));

        $p->state('paying', fn (Builder $s) => $s
            ->invoke(ChargeCard::class, into: 'paymentId', onDone: 'shipping', onError: 'failed'));

        $p->state('shipping', fn (Builder $s) => $s
            ->on('shipped', to: 'done')
            ->after('7 days', to: 'lost'));

        $p->final('done');
        $p->final('failed');
        $p->final('lost');
    }

    private function keepItems(array &$context, array $event): void
    {
        $context['items'] = $event['items'] ?? [];
    }
}
```

第一个声明的状态就是初始状态。每个 id 在流程内唯一，因此转换目标直接用 id 表示。

## 2. 编写副作用

所有与外部世界交互的操作都是**副作用（effect）**：一个带有 `__invoke()` 的类，
并通过一个属性（attribute）声明重复执行是否安全。

```php
use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Effect\Effect;

#[AtMostOnce]
final class ChargeCard
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function __invoke(array $context, Effect $fx): string
    {
        return $this->gateway->charge($context['items'], idempotencyKey: $fx->key);
    }
}
```

`#[AtMostOnce]` 表示扣款绝不会被盲目重复：如果 worker 在调用过程中崩溃，该副作用
会变为 `unknown`，等待对账或人工处理。参见[副作用](effects.md)。

## 3. 运行

```php
use Dskripchenko\ProcessEngine\Engine;

use Dskripchenko\ProcessEngine\Runtime\SimpleContainer;

$container = new SimpleContainer;                       // or your framework's container
$container->set(ChargeCard::class, new ChargeCard($gateway));

$engine = Engine::inMemory(container: $container);
$order = $engine->start(OrderFlow::class, ['customer' => 'c-17'], key: 'order-1001');

$engine->send($order->id(), 'submit', ['items' => ['sku-1']], key: 'http-req-77');
$engine->drain();

$view = $engine->get($order->id());
$view->active();      // ['shipping']
$view->expects();     // shipped, a timer in 7 days
$view->journal();     // what happened and why
```

`Engine::inMemory()` 将所有数据保存在内存中，并在 `drain()` 中同步执行工作：适用于
测试和脚本。在生产环境中，引擎运行在数据库存储之上，配合 worker（`runInstance()`、
`executeEffect()`，以及每分钟一次的 `tick()`）——Laravel 桥接包会完成这些装配。

## 4. 测试

```php
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

ProcessTest::start(OrderFlow::class)
    ->fake(ChargeCard::class, returns: 'pay-1')
    ->send('submit', ['items' => ['sku-1']])
    ->assertIn('shipping')
    ->travel('8 days')
    ->assertFinal('lost');
```

关于崩溃、未知结果和竞态，参见[测试](testing.md)。

## 下一步

- [概念与保证](concepts.md)
- [定义流程](builder.md)
- [副作用与重试语义](effects.md)
