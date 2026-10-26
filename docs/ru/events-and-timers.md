# События, таймеры и опрос

## Отправка события экземпляру

```php
$engine->send($instanceId, new FormSubmitted($form), key: $requestId, by: Actor::user($userId));
$engine->send($instanceId, 'client.signed', ['at' => '2026-10-26'], key: 'sig-881');
```

- Событие — это объект (его публичные свойства становятся payload; имя — это
  его класс или `#[Event('name')]`) либо имя с массивом.
- **Ключ делает доставку идемпотентной.** Отправьте один и тот же ключ дважды,
  и второй вызов вернёт `Receipt::DUPLICATE`. Без ключа используется случайный,
  который ни от чего не защищает, — передавайте ключ для всего, что может
  повториться (HTTP-запросы, вебхуки, сообщения очереди).
- `by:` записывает в журнал, кто это сделал.
- Событие, которое текущее состояние не принимает, **игнорируется**, а журнал
  объясняет почему и что ожидалось. Асинхронные стороны не должны терять
  ответы из-за тайминга, поэтому для отправителя это не ошибка.

`sendStrict()` сначала проверяет и возвращает `Receipt::REJECTED` с
ожидаемыми событиями — для интерфейсов, которым нужно сказать «форма уже
отправлена».

### Валидация событий

Класс события, реализующий `SelfValidating`, проверяет себя до постановки в
очередь; исключение получает отправитель, а процесс никогда не видит плохих
данных:

```php
final class FormSubmitted implements SelfValidating
{
    public function __construct(public array $form) {}

    public function validate(): void
    {
        if (empty($this->form['damage'])) {
            throw new InvalidArgumentException('Describe the damage');
        }
    }
}
```

`$engine->addValidator(fn (Event $e) => ...)` добавляет проверку для каждого
события.

## Публикация по ключу корреляции

Когда другая сторона знает только свою ссылку (номер заявки), состояние
подписывается под этим ключом:

```php
->state('awaitingParty', fn (Builder $s) => $s
    ->correlate([PartyDecision::class, DocumentsRequested::class], by: 'externalId')
    ->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...)))
```

```php
$engine->publish(new PartyDecision(approved: true), correlate: 'APP-123', key: $webhookMessageId);
```

- Подписка создаётся при входе в состояние из свойства контекста `by:` и
  удаляется при выходе. Подписывайтесь там, где ключ уже известен.
- Опубликованное событие, которого пока никто не ждёт, **буферизуется** (по
  умолчанию на 7 дней) и доставляется, как только экземпляр подпишется: ответ,
  пришедший раньше, чем процесс начал его ждать, не теряется.
- Дубликаты (то же событие, ключ и ключ корреляции) отбрасываются.

## Токены задач

Обратный вызов, адресованный текущей активации состояния:

```php
$token = $engine->taskToken($instanceId, 'awaitingSignature');   // put it into a callback URL
// later, in the callback handler:
$engine->complete($token, 'signature.completed', $payload);
```

Токен подписан (HMAC с секретом движка). Поддельный токен вызывает
исключение; токен завершённой активации возвращает `Receipt::REJECTED`.

## Откладывание

```php
->state('submitting', fn (Builder $s) => $s->defer(PartyDecision::class))
```

Отложенное событие остаётся в inbox, пока не станет активным состояние,
которое его принимает (по умолчанию до 30 дней), вместо того чтобы
игнорироваться.

## Таймеры

```php
->after('10 days', to: 'reminded')                    // from entering the state
->after('PT2H', run: RemindClient::class)              // ISO 8601; no target: stay
->after($this->slaFor(...), to: 'escalated')           // a method returning a duration or a moment
->after(businessDays(5), to: 'late')                   // through a BusinessCalendar
->at('deadline', to: 'expired')                        // a context property with an ISO 8601 date
```

- Таймер принадлежит одной активации своего состояния: выход из состояния
  отменяет его; повторный вход запускает новый. Таймер, сработавший после
  выхода из состояния, отбрасывается.
- Срабатывание идемпотентно (ключ таймера), поэтому двойной тик ничего не
  делает.
- Длительности: `14 days`, `1 day, 2 hours`, `10s`, `2h`, `P14D`, `PT10M`.
- Рабочие дни считаются через `BusinessCalendar` (по умолчанию: с понедельника
  по пятницу). Движок не знает праздников; передайте ему свой календарь.
- Точность равна точности тика: запускайте `tick()` каждую минуту
  (планировщиком) или цикл воркера для секунд. Таймеры нужны для бизнес-сроков,
  а не для миллисекунд.

<a id="polling"></a>

## Опрос

Для сторон, которые не умеют делать обратный вызов:

```php
->state('waitingForSignature', fn (Builder $s) => $s
    ->poll(CheckSignature::class, every: '1 minute')
    ->on(SignatureCompleted::class, to: 'signed')
    ->on('signature.rejected', to: 'refused')
    ->after('24 hours', to: 'expired'))
```

```php
#[Idempotent]
final class CheckSignature
{
    public function __invoke(array $context): SignatureCompleted|string|null
    {
        $state = $this->service->status($context['requestId']);

        return match ($state) {
            'complete' => new SignatureCompleted(),
            'rejected' => 'signature.rejected',
            default    => null,          // nothing new: no event, no journal entry
        };
    }
}
```

- Обработчик должен быть `#[Idempotent]`.
- `null` значит «пока ничего»: ни события, ни **записи в журнале**, только
  счётчики. Опрос каждую минуту в течение суток ничего не стоит журналу.
- Событие (объект или имя) или список событий доставляется экземпляру.
- `every:` может быть методом процесса, который вычисляется заново перед
  каждым запуском: опрашивать каждые 5 минут, пока клиент онлайн, и раз в час
  в остальное время.
- Сбои обрабатываются по политике повтора обработчика; когда попытки
  кончаются, открывается инцидент, а опрос продолжается с максимальным
  интервалом.
- Событие может вызвать внеочередной запуск: `->on('client.opened-page', run: CheckSignature::class)`.

### Рассылка

Опрос (или любой эффект) может адресовать другие экземпляры:

```php
public function __invoke(array $context): array
{
    return array_map(
        fn (array $claim) => Publish::to($claim['id'], new ClaimStatusChanged($claim['status'])),
        $this->insurer->clientClaims($context['clientId']),
    );
}
```

`Publish::to($correlationKey, $event)` идёт через подписки и буфер;
`Send::to($instanceId, $event)` идёт прямо в inbox. Один запрос по всем
заявкам клиента гораздо дешевле, чем отдельный опрос на каждую заявку.
