# Описание процессов

## Класс процесса

```php
use Dskripchenko\ProcessEngine\Attributes\ProcessName;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

#[ProcessName('claim-settlement')]        // optional; default: kebab-case class name
final class ClaimSettlement extends Process
{
    public const VERSION = 1;               // required; bump it whenever the graph changes
    public const STATES = ClaimState::class; // optional: an enum of state ids, checked against the graph

    public function define(Builder $p): void { /* the graph */ }

    public function projections(): array { return []; } // optional, see the Laravel bridge
}
```

Класс создаётся **без аргументов конструктора**. Поэтому гарды и действия не
могут обратиться к сервисам или выполнить ввод-вывод; всё внешнее — это
эффект. Именно так шаг остаётся чистой функцией.

## Правила билдера

- Id состояний уникальны в пределах процесса; цели указываются по id.
- Первый объявленный дочерний элемент — начальное состояние (переопределяется
  через `initial()`).
- Гарды и действия — это **методы процесса**, записанные как
  `$this->method(...)`, или декларативные предикаты и действия. Анонимные
  замыкания отклоняются: их нельзя сохранить и версионировать.
- Эффекты — классы с `#[Idempotent]` или `#[AtMostOnce]`; класс без
  семантики повтора отклоняется.
- Id состояний могут быть строками или кейсами backed enum (`ClaimState::Review`).

`define()` компилируется в канонический массив при первом использовании.
Ошибки (неизвестные цели, отсутствующая семантика, неизвестные методы,
ненаправленные финальные состояния дочернего процесса) роняют сборку со
списком всех проблем; предупреждения указывают на недостижимые состояния и
выборы, которые могут застрять.

## Состояния

```php
$p->state('review', fn (Builder $s) => $s /* … */);   // atomic, or compound with children
$p->parallel('agreement', fn (Builder $s) => $s
    ->region('money', fn (Builder $r) => $r /* … */)
    ->region('service', fn (Builder $r) => $r /* … */), join: 'all'); // or 'any'
$p->final('closed', output: 'payoutId');              // a context property or a method
$p->choice('route')->when($this->isMoney(...), 'payout')->otherwise('finalizing');
$p->history('resume', deep: true);                    // inside a compound state
```

Вложенность, параллельные регионы, история и дочерние процессы описаны в
разделе [структура](structure.md).

## Переходы

```php
->on('client.signed', to: 'review')
->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...))
->on(PartyDecision::class, to: 'rejected', run: $this->storeReason(...))   // first matching wins
->on('client.edit', run: $this->touch(...))                                 // no target: internal
->always(to: 'next', when: $this->ready(...))                               // eventless
->after('10 days', to: 'reminded')                                          // timer, see events
->onDone(to: 'agreement')                                                   // the compound reached its final
```

Параметры `on()`:

| Параметр | Значение |
|---|---|
| `to:` | целевое состояние; без него переход внутренний (без выхода и входа) |
| `when:` | гард: метод, возвращающий `bool`, или предикат |
| `run:` | действия: методы, классы эффектов, `assign()`/`raise()`/`note()` или их список |
| `label:` | подпись для интерфейсов (`expects()`, кнопки) |
| `from:` | только на уровне процесса: исходные состояния или `'*'` для любого незавершённого состояния |
| `match:` + `targets:` + `otherwise:` | таблица маршрутизации по полю события |
| `spawn:` + `input:` + `key:` | запуск дочернего процесса на каждое событие (см. структуру) |

### Таблицы маршрутизации

```php
->on(ClaimStatusChanged::class, match: 'status', targets: [
    'upload_documents' => 'awaitingDocuments',
    'payment'          => 'paying',
    'done'             => 'settled',
], otherwise: 'reviewing')

$p->choice('kind')->match('scenario', ['documents' => 'documents', 'inspection' => 'inspection'], otherwise: 'info');
```

Каждое значение превращается в обычный переход с гардом, так что граф
остаётся явным и его можно нарисовать.

### Из любого состояния

```php
$p->on('client.cancelled', to: 'cancelling', from: ['application', 'review']);
$p->on('client.cancelled', to: 'cancelled', from: '*');
```

## Гарды и действия как методы

Аргументы внедряются **по типу и имени**:

| Параметр | Получает |
|---|---|
| класс контекста (`ClaimContext $c`) | объект контекста (изменения сохраняются) |
| `array $context` / `array &$context` | массив контекста (по ссылке, чтобы его изменить) |
| класс события (`PartyDecision $e`) | событие, восстановленное из payload |
| `array $event` | payload события |
| `StepContext $step` | время шага, id экземпляра, `isIn()`, `spawned()` |
| прочие | дополнительные значения (элемент `forEach`, …) |

```php
private function isApproved(PartyDecision $e): bool { return $e->approved; }
private function countRequest(ClaimContext $c): void { $c->documentRequests++; }
private function sla(array $context): string { return $context['vip'] ? '1 hour' : '1 day'; }
```

## Контекст

Массив или объект с публичными свойствами (promoted-параметры конструктора
подходят). Поддерживаемые типы: скаляры, массивы, backed enum,
`DateTimeImmutable`, вложенные объекты; списку объектов нужен
`#[ListOf(Item::class)]`. Крупные данные (файлы, документы) держите снаружи и
храните ссылки. Лимит размера по умолчанию — 256 КБ JSON.

Эффекты никогда не меняют контекст напрямую: они возвращают результат,
который сохраняется через `into:` или используется методом `onDone`. Так
каждое изменение контекста остаётся внутри транзакции шага.

## Декларативные предикаты и действия

Для описаний, загруженных из JSON, или коротких гардов:

```php
use function Dskripchenko\ProcessEngine\{eq, gt, in, exists, all, any, not, flag, assign, fromEvent, inc, raise, note};

->on('pay', to: 'paid', when: all(gt('$.event.amount', 0), not(exists('blocked'))))
->on('edit', run: assign('form', fromEvent('form')))
->on('retry', run: [assign('attempts', inc(1)), note('retried by the client')])
->on('fast', to: 'express', when: flag('express-lane'))   // a host feature switch
```

Пути без `$.` — это свойства контекста (`'amount'` — это `$.context.amount`).
Операторы: `eq ne gt gte lt lte in exists empty all any not flag`.

## Активности состояния

```php
->entry(NotifyClient::class, $this->stamp(...))   // on entry: effects and methods
->exit(note('left review'))
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing')   // lives while the state is active
->poll(CheckSignature::class, every: '1 minute')                       // see events
->correlate([PartyDecision::class], by: 'externalId')                  // see events
->defer(PartyDecision::class)                                          // keep until a state accepts it
->compensate(onDone: 'cancelled')                                      // see effects
->meta(label: 'Under review', description: 'The insurer checks the claim')
```

### Опции `invoke()`

| Опция | Значение |
|---|---|
| `into:` | свойство контекста, куда попадает результат |
| `input:` | метод, строящий входные данные (`Effect::$input` или контекст дочернего процесса) |
| `forEach:` | список в контексте: один вызов на элемент, результаты собираются по порядку |
| `concurrency:` | не больше стольких одновременных вызовов `forEach` |
| `onDone:` | цель; для дочернего процесса — карта «финальное состояние → цель» |
| `onError:` | цель или карта «код ошибки → цель» (`'*'` для остальных) |
| `onUnknown:` | куда идти, если исход at-most-once неизвестен (по умолчанию: ждать) |
| `retry:` | политика `Retry`, переопределяющая политику эффекта по умолчанию |

## Цепочки эффектов

```php
use function Dskripchenko\ProcessEngine\step;

$p->steps('registration', [
    step(AddParticipant::class, forEach: 'participants', into: 'participantIds'),
    step(CreateClaims::class, into: 'claims'),
    step(AttachDocument::class, forEach: 'documents', concurrency: 5),
], onDone: 'settlement', onError: 'registrationFailed');
```

Каждый шаг становится отдельным состоянием (его видно в журнале и во flow)
и переходит к следующему, когда его эффект выполнен.

## Подписи для интерфейсов

`meta(label:)` у состояний и `label:` у переходов движок не читает.
`InstanceView::label()` возвращает подпись самого внутреннего активного
состояния с подписью; `expects()` возвращает события, принимаемые сейчас, с
их подписями — кнопки, которые может нажать клиент.
