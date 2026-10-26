# Тестирование процессов

`ProcessTest` синхронно запускает процесс на движке в памяти с виртуальными
часами, фейковыми эффектами и внедрением сбоев. На этом же стенде построен
собственный набор тестов движка.

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

## Управление

| Метод | |
|---|---|
| `ProcessTest::start($process, $context, fakes: [...])` | запустить и выполнять, пока есть работа |
| `new ProcessTest(store:, clock:, container:)`, затем `->begin(...)` | с настоящим хранилищем (базой данных) и вашим контейнером |
| `send($event, $payload, key:)`, `publish($event, correlate:, key:)` | доставить и выполнять, пока есть работа |
| `travel('3 days', step: '1 hour')` | двигать часы шаг за шагом, по порядку запуская таймеры и повторы |
| `tick()`, `drain()` | один тик; выполнить то, чему пришло время |
| `crashAt($point, skip: 0)` | заставить среду выполнения «умереть» в точке (см. ниже) |
| `recover('10 minutes')` | снять сбои, дать арендам истечь, дать тику подобрать осколки |
| `resolveEffect($class, succeeded:, result:)` | оператор закрывает эффект |
| `view()`, `engine`, `clock`, `signals` | заглянуть внутрь |

## Фейки

```php
->fake(ChargeCard::class, returns: 'tx-1')
->fake(ChargeCard::class, throws: new RuntimeException('timeout'))             // ambiguous
->fake(ChargeCard::class, sequence: [new EffectNotExecuted('refused'), 'tx-2']) // answers per call
->fake(ChargeCard::class, using: fn (Effect $fx) => 'tx-'.$fx->index)
->fake(ChargeCard::class, returns: 'tx-1', reconcile: Outcome::succeeded('tx-1'))
->fake(ChargeCard::class, returns: 'tx-1', crash: 'after-call')  // dies after the call, before the record
->fake(BookService::class, compensateThrows: new RuntimeException('down'))
```

Фейк записывает вызовы (`fakeOf($class)->calls`, `->reconciles`,
`->compensations`). Семантика по-прежнему берётся из атрибута настоящего
класса.

## Проверки

`assertIn(...$states)`, `assertNotIn()`, `assertFinal($state)`,
`assertStatus($status)`, `assertContext('path.to.value', $expected)`,
`assertCalls($class, $n)` (вызовы фейка), `assertRan($class, $n)`
(успешные эффекты), `assertEffect($class, $status)`,
`assertIncident($type)`, `assertNoIncidents()`.

## Падения

Точки сбоя, в которых можно заставить среду выполнения умереть:

| Точка | Ситуация |
|---|---|
| `runner.before_commit` | шаг вычислен, ничего не сохранено |
| `runner.after_commit` | шаг сохранён, звонок не прозвенел |
| `effect.after_claim` | эффект помечен как выполняющийся, обработчик не вызван |
| `effect.after_call` | обработчик вернул результат, результат не сохранён |
| `effect.after_record` | результат сохранён |
| `reconcile.after_call` | сверка вернула результат, он не сохранён |
| `ticker.after_fire` | таймер помечен сработавшим, его событие не поставлено в очередь |

Падение откатывает открытую транзакцию и оставляет аренды так, как их
оставил бы убитый воркер. После `recover()` проверьте инварианты, которые
вам важны:

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

Собственный набор тестов движка прогоняет каждую точку сбоя на нескольких
срабатываниях через два сценария и проверяет: не осталось ожидающих событий,
не более одного вызова на ключ at-most-once, ключи эффектов уникальны, один
шаг на внешнее событие, а свёртка патчей контекста из журнала даёт
сохранённый контекст.

## Гонки

Два движка на одном хранилище с разными id воркеров моделируют двух воркеров:

```php
$store = new InMemoryStore;
$clock = new VirtualClock;
$a = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'A');
$b = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'B');
```

Набор тестов моста для Laravel добавляет настоящие процессы (`pcntl_fork`)
на SQLite, MySQL и PostgreSQL, а также воркер, убитый через SIGKILL посреди
вызова.
