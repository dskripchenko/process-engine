# Начало работы

## Установка

```bash
composer require dskripchenko/process-engine
```

Нужен PHP 8.2 или новее, других зависимостей нет. Для Laravel установите
вместо этого мост: `composer require dskripchenko/laravel-process-engine` (он
подтягивает ядро и добавляет хранилище в базе данных, воркеры очереди и
artisan-команды).

## 1. Опишите процесс

Процесс — это класс с версией и методом `define()`, который строит граф.
Гарды и изменения контекста — небольшие методы того же класса, на них
ссылаются как `$this->method(...)`.

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

Первое объявленное состояние — начальное. Каждый id уникален в пределах
процесса, поэтому цели переходов указываются по id.

## 2. Напишите эффекты

Всё, что касается внешнего мира, — это **эффект**: класс с `__invoke()` и
атрибутом, который говорит, безопасно ли его повторять.

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

`#[AtMostOnce]` значит, что списание никогда не повторяется вслепую: если
воркер упал посреди вызова, эффект переходит в `unknown` и ждёт сверки или
оператора. См. [эффекты](effects.md).

## 3. Запустите

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

`Engine::inMemory()` держит всё в памяти и выполняет работу синхронно в
`drain()`: это подходит для тестов и скриптов. В продакшене движок работает
на хранилище в базе данных с воркерами (`runInstance()`, `executeEffect()`,
`tick()` каждую минуту) — это настраивает мост для Laravel.

## 4. Протестируйте

```php
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

ProcessTest::start(OrderFlow::class)
    ->fake(ChargeCard::class, returns: 'pay-1')
    ->send('submit', ['items' => ['sku-1']])
    ->assertIn('shipping')
    ->travel('8 days')
    ->assertFinal('lost');
```

Падения, неизвестные исходы и гонки описаны в разделе [тестирование](testing.md).

## Дальше

- [Концепции и гарантии](concepts.md)
- [Описание процессов](builder.md)
- [Эффекты и семантика повтора](effects.md)
