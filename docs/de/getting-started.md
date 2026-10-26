# Erste Schritte

## Installation

```bash
composer require dskripchenko/process-engine
```

PHP 8.2 oder neuer, keine weiteren Abhängigkeiten. Für Laravel installieren Sie
stattdessen die Bridge: `composer require dskripchenko/laravel-process-engine`
(sie zieht den Kern mit und ergänzt einen Datenbank-Store, Queue-Worker und
Artisan-Befehle).

## 1. Den Prozess beschreiben

Ein Prozess ist eine Klasse mit einer Version und einer Methode `define()`, die
den Graphen aufbaut. Guards und Kontextänderungen sind kleine Methoden derselben
Klasse, referenziert als `$this->method(...)`.

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

Der zuerst deklarierte Zustand ist der Anfangszustand. Jede ID ist innerhalb des
Prozesses eindeutig, daher werden Ziele über ihre ID angegeben.

## 2. Die Effekte schreiben

Alles, was die Außenwelt berührt, ist ein **Effekt**: eine Klasse mit
`__invoke()` und einem Attribut, das angibt, ob eine Wiederholung sicher ist.

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

`#[AtMostOnce]` bedeutet, dass die Abbuchung nie blind wiederholt wird: Stirbt
ein Worker mitten im Aufruf, wird der Effekt `unknown` und wartet auf einen
Abgleich oder einen Operator. Siehe [Effekte](effects.md).

## 3. Ausführen

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

`Engine::inMemory()` hält alles im Speicher und führt die Arbeit synchron in
`drain()` aus: richtig für Tests und Skripte. In Produktion läuft die Engine auf
einem Datenbank-Store mit Workern (`runInstance()`, `executeEffect()`, `tick()`
jede Minute) — die Laravel-Bridge richtet das ein.

## 4. Testen

```php
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

ProcessTest::start(OrderFlow::class)
    ->fake(ChargeCard::class, returns: 'pay-1')
    ->send('submit', ['items' => ['sku-1']])
    ->assertIn('shipping')
    ->travel('8 days')
    ->assertFinal('lost');
```

Siehe [Testen](testing.md) für Abstürze, unbekannte Ergebnisse und Race Conditions.

## Weiter

- [Konzepte und Garantien](concepts.md)
- [Prozesse definieren](builder.md)
- [Effekte und Wiederholungssemantik](effects.md)
