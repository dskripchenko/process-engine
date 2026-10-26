# Эффекты и семантика повтора

**Эффект** — это всё, что касается внешнего мира: вызов API, платёж, письмо,
сгенерированный документ. Эффекты никогда не выполняются внутри шага: шаг
складывает их в outbox, а выполняются они после фиксации шага, вне всякой
транзакции или блокировки. Их исход возвращается в процесс как событие.

## Семантика обязательна

```php
use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
```

| Атрибут | Гарантия | Когда исход под сомнением |
|---|---|---|
| `#[Idempotent]` | выполняется как минимум один раз | повторяется по своей политике |
| `#[AtMostOnce]` | выполняется не более одного раза на ключ | становится `unknown`: автоматического повтора нет |

Значения по умолчанию нет. Класс эффекта без одного из этих атрибутов
отклоняется при сборке процесса — автор должен решить, безопасно ли
повторять вызов. Отправить письмо дважды — досадно; списать деньги с карты
дважды — недопустимо.

## Как написать эффект

```php
#[AtMostOnce]
final class SubmitToInsurer implements Reconcilable
{
    public function __construct(private readonly InsurerApi $api) {}  // any services

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        try {
            return $this->api->submit($claim->application, idempotencyKey: $fx->key)->id;
        } catch (ConnectionRefused $e) {
            throw new EffectNotExecuted($e);   // never reached the other side
        }
    }

    public function reconcile(ClaimContext $claim, Effect $fx): Outcome
    {
        $found = $this->api->findByIdempotencyKey($fx->key);

        return $found ? Outcome::succeeded($found->id) : Outcome::stillUnknown();
    }
}
```

Аргументы внедряются по типу: контекст (объект или `array $context`),
`Effect $fx`, а `$item` / `$input` / `$result` — по имени. `Effect` содержит:

| Поле | |
|---|---|
| `key` | ключ идемпотентности: одинаковый для всех повторов одного вызова, новый для новой активации состояния |
| `instanceId`, `attempt`, `now` | |
| `item`, `index` | элемент `forEach` |
| `input` | то, что построил `invoke(..., input:)` |

**Передавайте `$fx->key` другой стороне** везде, где она поддерживает ключи
идемпотентности. Именно это делает сверку возможной.

## Как классифицируется исход

| Обработчик… | Результат |
|---|---|
| возвращает значение | `succeeded`; процесс получает `done.invoke.<id>` с `output` |
| выбрасывает `EffectNotExecuted` | вызова точно не было: повторяется (при любой семантике), пока есть попытки |
| выбрасывает `EffectFailed($code)` | вызов был, и в нём отказали: `failed`, `error.invoke.<id>` с кодом, без повтора |
| выбрасывает что-то другое, превышает таймаут или его воркер умирает | неоднозначно: `#[Idempotent]` повторяется, `#[AtMostOnce]` становится `unknown` |

Практические правила для HTTP-клиентов: отказ в соединении или ошибка DNS до
отправки → `EffectNotExecuted`; бизнес-ответ 4xx → `EffectFailed`; таймаут
чтения или 5xx после отправки → пусть исключение летит дальше (неоднозначно).

## Не более одного раза: точно

1. Эффект захватывается: `running`, attempt + 1, аренда. Эта отметка
   «начато» фиксируется **до** вызова.
2. Выполняется обработчик.
3. Результат фиксируется вместе с событием для процесса.

Если воркер умирает между 1 и 3, аренда истекает, и тик помечает эффект как
`unknown` — никогда не `pending`. Процесс получает `unknown.invoke.<id>`.

Дальше:
- с `Reconcilable` метод `reconcile()` запускается по своей политике (по
  умолчанию 10 попыток, от 1 минуты до 6 часов). `succeeded`/`failed`
  закрывают эффект так, будто вызов вернулся; `notExecuted` разрешает ещё один
  настоящий вызов; `stillUnknown` пробует позже и, когда попытки кончаются,
  открывает инцидент;
- без него инцидент открывается сразу.

Процесс ждёт в состоянии (или идёт в `onUnknown:`, если он объявлен).
Оператор закрывает вопрос через `resolveEffect()`, проверив другую сторону,
или перезапускает эффект через `retryEffect(..., confirmUnsafe: true)`, беря
ответственность на себя в журнале.

## Политики повтора

```php
use Dskripchenko\ProcessEngine\Effect\Retry;

#[Idempotent(
    retry: new Retry(attempts: 5, initialInterval: '10 seconds', backoff: 2.0, maxInterval: '1 hour',
                     jitter: true, attemptTimeout: '2 minutes', onExhausted: 'error'),
    nonRetryable: [ValidationError::class],
)]
```

| Поле | По умолчанию (idempotent / at most once) |
|---|---|
| `attempts` | 5 / 3 (эффект at-most-once повторяется только при `EffectNotExecuted`) |
| `initialInterval`, `backoff`, `maxInterval`, `jitter` | 10 с, ×2, 1 ч, включён |
| `attemptTimeout` | 2 минуты: аренда одной попытки; более долгий вызов считается мёртвым |
| `onExhausted` | `error`: `error.invoke.<id>` с кодом `retries_exhausted` (если не обработан → инцидент); `incident`: инцидент, процесс ждёт; `fail`: экземпляр падает |

`invoke(..., retry: new Retry(...))` переопределяет политику в одном месте
графа; семантика остаётся той, что у класса.

## Результаты и ошибки в графе

```php
->invoke(RenderApplication::class, into: 'docId', onDone: 'signing', onError: [
    'bad_template' => 'manualCheck',
    '*'            => 'failed',
])
```

Эффект может также вернуть `Publish::to($correlationKey, $event)` или
`Send::to($instanceId, $event)` (или их список), чтобы доставить события
другим экземплярам — см. [события](events-and-timers.md).

Эффекты в `entry()`, `exit()` и `run:` работают по принципу «запустил и
забыл»: их исход не двигает процесс, но они следуют той же семантике, а
эффект, который в итоге упал, открывает инцидент.

## Компенсация (сага)

Эффект, который можно отменить, реализует `Compensable`:

```php
#[AtMostOnce]
final class PayOut implements Compensable, Reconcilable
{
    public function __invoke(ClaimContext $claim, Effect $fx): string { /* transfer */ }
    public function reconcile(Effect $fx): Outcome { /* look up by $fx->key */ }
    public function compensate(mixed $result): void { /* refund the transfer $result */ }
}
```

```php
$p->state('cancelling', fn (Builder $s) => $s->compensate(onDone: 'cancelled'));
// or for one stage:
$p->state('undoBooking', fn (Builder $s) => $s->compensate(onDone: 'choosingSlot', scope: 'agreement'));
```

- При входе завершённые компенсируемые эффекты (процесса или указанного
  состояния) отменяются **в обратном порядке завершения, по одному**.
- `compensate()` получает исходный результат; он повторяется (по умолчанию
  20 попыток), а затем открывает инцидент.
- Эффект в `unknown` внутри области **блокирует** компенсацию (с
  инцидентом): отменять то, чего, может быть, не было, неправильно.
- `Engine::cancel($id, ..., compensate: true)` компенсирует весь процесс,
  затем отменяет его.

## Опросы

Опрос — это периодический идемпотентный эффект, привязанный к состоянию; см.
[события](events-and-timers.md#polling).
