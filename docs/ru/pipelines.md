# Конвейеры данных: пакеты и выключаемые этапы

Бизнес-процессы движутся в человеческом темпе: согласование днями ждёт
подписи. Конвейеры данных устроены иначе: этап обрабатывает **тысячи или
миллион элементов** (объекты для обогащения, регионы для загрузки, прокси для
проверки), каждый элемент завершается сам по себе, внешний сервис
ограничивает частоту запросов, а этап выключают, когда его сервис лежит или
нет ключа. Для этого есть две конструкции: **пакеты** и **выключаемые
состояния**.

## Пакеты

```php
use Dskripchenko\ProcessEngine\Batch\BatchHandler;
use Dskripchenko\ProcessEngine\Batch\BatchResult;
use Dskripchenko\ProcessEngine\Batch\ItemOutcome;
use Dskripchenko\ProcessEngine\Batch\ItemSource;

$p->state('dadata', fn (Builder $s) => $s
    ->batch(DadataLookup::class, items: ObjectsToEnrich::class, chunk: 50, concurrency: 2,
        itemAttempts: 3, into: 'counters.dadata', onDone: 'nspd', onError: ['fatal' => 'nspd']));
```

Ключи элементов приходят **постранично из источника**, а не через контекст,
поэтому пакет из миллиона элементов не раздувает экземпляр:

```php
final class ObjectsToEnrich implements ItemSource
{
    public function __construct(private readonly Objects $objects) {}

    /** The next page strictly after $after, in a stable order; [] — no more. */
    public function keys(array $context, ?string $after, int $limit): array
    {
        return $this->objects->idsOfGroup($context['group'], after: (int) $after, limit: $limit);
    }

    /** Optional: the total, for progress. */
    public function count(array $context): ?int
    {
        return $this->objects->countOfGroup($context['group']);
    }
}
```

**Обработчик** получает порцию ключей и сообщает исход каждого:

```php
#[Idempotent(retry: new Retry(attempts: 10, initialInterval: '1 minute', maxInterval: '1 hour'))]
final class DadataLookup implements BatchHandler
{
    public function handle(array $context, array $keys, Effect $fx): BatchResult
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = match ($this->lookup($key)) {
                'found' => ItemOutcome::done(),
                'none' => ItemOutcome::notFound(),
                default => ItemOutcome::failed('timeout'),
            };
        }

        return BatchResult::of($out);
    }
}
```

| Параметр | По умолчанию | |
|---|---|---|
| `chunk` | 100 | ключей на один вызов обработчика |
| `concurrency` | 1 | порций одновременно |
| `itemAttempts` | 3 | попыток одного элемента, прежде чем он останется `failed` |
| `retry:` | политика обработчика | как повторяется порция |
| `into:` | — | куда в контексте попадают счётчики |

Обработчик обязан быть `#[Idempotent]`: повторённая порция повторяет свои
элементы.

### Исходы

| Обработчик… | Результат |
|---|---|
| возвращает исходы | `done`, `not_found`, `skipped`, `deferred` окончательны; `failed` пробуется снова в одной из следующих порций, пока не исчерпан `itemAttempts`, затем остаётся `failed`. Ключ, которого нет в результате, считается упавшим |
| выбрасывает `EffectNotExecuted` (квота, пауза, отказ в соединении) | порция повторяется по своей политике с растущей паузой: этап **ждёт**, а не падает |
| выбрасывает что-то другое | то же самое: обработчик идемпотентен |
| исчерпал повторы порции | каждый элемент порции теряет одну попытку; пакет продолжается |
| выбрасывает `EffectFailed($code)` (нет ключа, нет баланса, бан) | **пакет останавливается**: то, что не выполнялось, становится `deferred`, процесс получает `error.invoke` с кодом — направьте его через `onError:` |

Когда источник прочитан до конца и у каждого элемента есть исход, процесс
получает `done.invoke` со счётчиками: `{done, not_found, failed, deferred, skipped, total}`.

### Выполнение

Элементы хранятся в хранилище (`BatchStore`), порции — обычные строки
эффектов: аренды, таймауты попыток и восстановление тиком работают как для
любого эффекта. Воркер, умерший где угодно, ничего не теряет: каждый элемент
завершается с исходом, а `done` доставляется один раз. Приостановленный
экземпляр не запускает новых порций; выход из состояния отменяет пакет и
откладывает то, что ждало.

Собственные затраты движка на PostgreSQL — около 10 000 элементов в секунду,
ровно от первого элемента до миллионного (порция 500, параллелизм 8,
обработчик, который только отвечает).

### Операции

```php
$engine->batches($instanceId);                       // BatchRecord: status, counters, total
$engine->batchItems($batchId, 'failed', limit: 100);  // elements with their reason and attempts
$engine->retryBatchItems($instanceId, $batchId, $by, 'the timeout is fixed', ['failed', 'deferred']);
$engine->skipBatch($instanceId, $batchId, $by, 'not needed today');
```

Обе операции работают с идущим пакетом и попадают в журнал. Завершённый
пакет повторяют новым запуском процесса по его элементам или переводом
экземпляра обратно в состояние (новый пакет с нуля).

Схема показывает идущий пакет на его узле: бейдж `finished / total` и строку
счётчиков.

## Выключаемые состояния

```php
$p->state('dadata', fn (Builder $s) => $s
    ->switchable('enrichment.dadata', skipTo: 'nspd', offOn: ['fatal'], label: 'DaData')
    ->batch(...));
```

Выключаемое состояние **пропускается** в `skipTo`, когда его переключатель
выключен. Оно ничего не вызывает, а пропуск отмечается в списке `skipped`
контекста (`{state, switch, reason}`), так что пропущенный этап никогда не
выглядит пройденным. Переключатель выключен:

- **везде** — `$engine->switchOff('enrichment.dadata', $by, 'no key yet')`,
  до `switchOn()`; экземпляры, стоящие в таком состоянии, сразу его покидают
  (их пакет отменяется);
- **на один запуск** — переключатель указан в контексте запуска: `['disabled' => ['enrichment.dadata']]`;
- **движком** — вызов в состоянии упал с кодом из `offOn`: переключатель
  выключается с сообщением ошибки в качестве причины и системой в качестве
  актора, а сигнал `switch.changed` позволяет приложению оповестить людей.

Переключатель, которого никто не трогал, **включён** (в отличие от флага).
Переключатели живут в `SwitchProvider`: в памяти — `StaticFlags` (по
умолчанию в движке); мост для Laravel хранит их в таблице.
`$engine->switchState($name)` сообщает, кто его переключил, когда и почему.
Этап, который должен выполняться всегда, просто не делают выключаемым.

`FlowExporter`, созданный с провайдером переключателей, помечает выключаемые
узлы через `enabled` и `data.switch`, так что схема показывает, какие этапы
выключены.
