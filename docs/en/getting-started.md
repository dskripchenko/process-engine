# Getting started

## Install

```bash
composer require dskripchenko/process-engine
```

PHP 8.2 or newer, no other dependencies. For Laravel install the bridge
instead: `composer require dskripchenko/laravel-process-engine` (it pulls the
core in and adds a database store, queue workers and artisan commands).

## 1. Describe the process

A process is a class with a version and a `define()` method that builds the
graph. Guards and context updates are small methods of the same class,
referred to as `$this->method(...)`.

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

The first state declared is the initial one. Every id is unique within the
process, so targets are written by id.

## 2. Write the effects

Anything that touches the outside world is an **effect**: a class with
`__invoke()` and an attribute that says whether repeating it is safe.

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

`#[AtMostOnce]` means the charge is never repeated blindly: if a worker dies
mid-call, the effect becomes `unknown` and waits for reconciliation or an
operator. See [effects](effects.md).

## 3. Run it

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

`Engine::inMemory()` keeps everything in memory and runs work synchronously in
`drain()`: right for tests and scripts. In production the engine runs on a
database store with workers (`runInstance()`, `executeEffect()`, `tick()`
every minute) — the Laravel bridge wires that up.

## 4. Test it

```php
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

ProcessTest::start(OrderFlow::class)
    ->fake(ChargeCard::class, returns: 'pay-1')
    ->send('submit', ['items' => ['sku-1']])
    ->assertIn('shipping')
    ->travel('8 days')
    ->assertFinal('lost');
```

See [testing](testing.md) for crashes, unknown outcomes and races.

## Next

- [Concepts and guarantees](concepts.md)
- [Defining processes](builder.md)
- [Effects and retry semantics](effects.md)
