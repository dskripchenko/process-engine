# Управление экземплярами

## Просмотр экземпляра

```php
$view = $engine->get($instanceId);     // or find() for null when missing

$view->status();          // running | suspended | completed | failed | cancelled
$view->active();          // every active state, outermost first
$view->leaves();          // where it stands
$view->is('review');      // also true for the final state of a finished instance
$view->label();           // client-facing status: meta.label of the innermost labelled state
$view->expects();         // events accepted now (guards on the context evaluated), timers
$view->context();         // the context object (or array); contextArray() for the array
$view->final(); $view->output();
$view->timers();          // pending, with fireAt; overdueTimers() for late ones
$view->subscriptions();   // correlation keys it listens on
$view->pendingEffects();  // running, waiting, unknown
$view->incidents();       // open ones
$view->children();
$view->journal();         // the history
$view->why();             // the journal entry that brought it here
$view->toArray();
```

`expects()` вычисляет гарды, которые зависят только от контекста; события,
чей гард читает само событие, помечаются как `conditional`. Используйте его,
чтобы показать действия, доступные клиенту сейчас.

Списки и счётчики:

```php
use Dskripchenko\ProcessEngine\Store\InstanceQuery;

$engine->instances(new InstanceQuery(state: 'awaitingDocuments', definitionName: 'claim-settlement'));
$engine->instances(new InstanceQuery(hasIncidents: true));
$engine->stateCounts(ClaimSettlement::class);   // state => instances, for an overview map
$engine->openIncidents();
$engine->health();   // running, suspended, with_incidents, open_incidents, overdue_timers, expired_effect_leases
```

## Журнал

У каждой записи есть тип, данные, актор, причина и время. Основные типы:

| Тип | Данные |
|---|---|
| `started`, `adopted`, `migrated` | версия, контекст, состояния |
| `step` | событие и его payload, выполненные переходы (с гардом, разрешившим каждый), кандидаты, отклонённые своими гардами, состояния выхода и входа, патч контекста (RFC 6902), заметки, команды |
| `event.ignored`, `event.deferred`, `event.discarded` | почему и что ожидалось |
| `effect.succeeded`, `effect.retry`, `effect.failed`, `effect.unknown`, `effect.reconciled`, `effect.lease_expired`, `effect.compensated`, `effect.orphaned` | ключ, класс, попытка, результат или ошибка |
| `timer.fired`, `poll.result`, `child.started` | |
| `incident.opened` | тип, сообщение |
| `manual.*`, `manual.rejected` | операция, параметры, актор, причина |
| `completed`, `failed`, `cancelled` | финальное состояние, output |

Payload хранится как есть: «кто что ввёл» видно в журнале.

## Ручные операции

Каждой операции нужны актор и **причина**. Операции идут через inbox,
поэтому выполняются по очереди с обычными событиями и попадают в журнал.

```php
$by = Actor::operator($user->id, $user->name);

$engine->transitionTo($id, ['awaitingParty'], $by, 'the insurer confirmed by phone');
$engine->transitionTo($id, ['review'], $by, 'reset', runExit: false, runEntry: false);
$engine->patchContext($id, [['op' => 'replace', 'path' => '/externalId', 'value' => 'APP-7']], $by, 'typo');
$engine->suspend($id, $by, 'fraud investigation');    // events wait, effects do not start
$engine->resume($id, $by, 'cleared');
$engine->cancel($id, $by, 'client withdrew');
$engine->cancel($id, $by, 'client withdrew', compensate: true);

$engine->retryEffect($effectId, $by, 'the provider is back');
$engine->retryEffect($effectId, $by, 'the bank confirmed nothing was charged', confirmUnsafe: true);
$engine->resolveEffect($effectId, $by, 'found the transfer in the bank statement', succeeded: true, result: 'TR-55');
$engine->resolveEffect($effectId, $by, 'the transfer was rejected', succeeded: false, code: 'rejected');
$engine->skipEffect($effectId, $by, 'sent by hand', result: null);
$engine->resolveIncident($incidentId, $by, 'handled');
$engine->redeliver($id, $inboxId, $by, 'retry after the fix');   // re-run an event whose step threw
```

- `transitionTo` игнорирует гарды. Действия выхода и входа выполняются, если
  их не отключить.
- `resolveEffect` / `skipEffect` доставляют обычное событие `done` / `error`,
  и процесс продолжается так, будто вызов вернулся.
- `retryEffect` для эффекта at-most-once с неизвестным исходом отклоняется
  без `confirmUnsafe: true`.
- Приостановленный экземпляр по-прежнему принимает ручные операции; его
  события ждут.

Авторизация — забота хост-приложения: проверяйте права до этих вызовов.

## Инциденты

| Тип | Когда |
|---|---|
| `effect_exhausted` | у эффекта кончились попытки при `onExhausted: 'incident'` |
| `effect_failed` | ошибку никто не обработал (нет `onError`), упал эффект «запустил и забыл» |
| `effect_unknown` | исход at-most-once не удалось выяснить |
| `compensation_failed`, `compensation_blocked` | отмена упала / ждёт неизвестного исхода |
| `step_error` | гард или действие выбросили исключение: событие помечается как упавшее, после исправления — `redeliver()` |
| `microstep_limit` | переходы без событий зациклились |
| `handler_missing` | пропал класс эффекта, на который ссылается живая версия |
| `context_too_large` | контекст превышает лимит |
| `poll_failing` | опрос раз за разом падает |

Экземпляр с открытыми инцидентами помечается (`hasIncidents`) и не попадает
в архив; остальная его часть продолжает работать там, где может.

## Сигналы

Передайте движку `EventListener`, чтобы получать сигналы после фиксации:
`instance.started`, `step.committed`, `event.ignored`, `effect.succeeded`,
`effect.failed`, `effect.unknown`, `incident.opened`, `incident.resolved`,
`instance.completed`, `instance.failed`, `instance.cancelled`. Мост для
Laravel превращает их в события `ProcessSignal`.

## Управление средой выполнения

| Вызов | Кто вызывает |
|---|---|
| `runInstance($id)` | воркер, когда у экземпляра есть события (в Laravel — задача очереди) |
| `executeEffect($id)` | воркер, когда пора выполнить эффект |
| `tick()` | каждую минуту: запускает таймеры, восстанавливает эффекты умерших воркеров, заново звонит по потерянной работе, удаляет просроченные сообщения |
| `archive()` | ежедневно: переносит в архив завершённые экземпляры старше 30 дней |
| `drain()` | синхронный режим (тесты, скрипты): выполняет всё, по чему позвонили, пока не станет нечего делать |

## Архив

Завершённые экземпляры без открытых инцидентов и живых дочерних процессов
переезжают в архив через `archiveAfter` (по умолчанию 30 дней): одна строка
на экземпляр со сжатыми снапшотом, журналом, эффектами и инцидентами. `get()`
по-прежнему возвращает их (`isArchived()`), только для чтения.
