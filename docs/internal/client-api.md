# Клиентский код: как это выглядит у пользователя движка

Дата: 2026-10-26. Статус: **на согласовании** — до кода.

Задача документа — показать, сколько «обвязки» пишет пользователь, и
согласовать API до этапа 1a. Код — эскиз целевого API; имена классов и
методов могут уточниться, принципы — нет.

## Принципы минимальной обвязки

1. **Процесс — один класс.** Граф в `define()`, guards и изменения
   контекста — маленькие методы этого же класса, на которые граф ссылается
   first-class callable: `$this->approved(...)`. Навигация в IDE и
   рефакторинг работают, строковых имён нет. В каноническую форму пишется
   имя метода (проверено: ссылка на метод отличима от анонимного замыкания;
   анонимные замыкания запрещены — их не сохранить).
2. **Этап, который что-то делает во внешнем мире, — обычный
   invokable-класс с DI** (сервис Laravel). От движка в нём — только атрибут
   семантики повтора (`#[Idempotent]` / `#[AtMostOnce]`) и, если нужно,
   метод `reconcile()` / `compensate()`. Внутри — любая сложность, движок
   туда не лезет.
3. **Сложный этап можно сделать отдельным процессом**: в `invoke()`
   передаётся класс процесса вместо класса эффекта — получается дочерний
   экземпляр со своим графом и версией.
4. **Никакой регистрации.** Ни реестра обработчиков, ни конфига со списком
   процессов, ни провайдера в приложении: классы резолвятся из контейнера
   по имени, определение регистрируется (с версией и hash) при первом
   `start()`. Для проверки в CI — `process-engine:validate app/Processes`.
5. **Типизированный контекст и события — по желанию.** Можно класс
   контекста (публичные свойства, движок сам сериализует) и классы событий,
   можно массивы и строки.
6. **Разумные умолчания:** первое объявленное состояние — начальное;
   id состояний уникальны в процессе, поэтому цели переходов пишутся просто
   `'review'`, без путей; политики повторов по умолчанию заданы для каждой
   семантики.

Что ядро **требует** явно, даже ценой лишней строки: семантику повтора у
каждого эффекта. Без атрибута класс эффекта не принимается.

## 1. Маленький процесс целиком

Согласование документа руководителем с напоминанием и эскалацией.

```php
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Builder;

final class DocumentApproval extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('pending', fn (Builder $s) => $s
            ->entry(NotifyApprover::class)
            ->after('2 days', run: NotifyApprover::class)            // reminder, stays in place
            ->after('5 days', to: 'escalated')
            ->on('approved', to: 'done')
            ->on('rejected', to: 'declined', run: $this->keepReason(...)));

        $p->state('escalated', fn (Builder $s) => $s
            ->entry(NotifyHead::class)
            ->on('approved', to: 'done')
            ->on('rejected', to: 'declined', run: $this->keepReason(...)));

        $p->final('done');
        $p->final('declined');
    }

    private function keepReason(array &$context, array $event): void
    {
        $context['reason'] = $event['reason'] ?? null;
    }
}

#[Idempotent]
final class NotifyApprover
{
    public function __construct(private Mailer $mail) {}

    public function __invoke(array $context, Effect $fx): void
    {
        $this->mail->to($context['approver'])->send(new ApprovalRequested($context['documentId']));
    }
}
```

Запуск и события (Laravel):

```php
$process = Processes::start(DocumentApproval::class, ['documentId' => $doc->id, 'approver' => $boss->email]);

Processes::send($process->id, 'approved', by: $request->user());
```

Обвязки здесь — `extends Process`, константа версии и `#[Idempotent]`.
Остальное — сам процесс.

## 2. Эталонный сложный процесс: урегулирование убытка

### Контекст

```php
final class ClaimContext
{
    public function __construct(
        public string $policyId,
        public array $application = [],
        public ?string $applicationDocId = null,
        public ?string $externalId = null,          // the insurer's application number
        public int $documentRequests = 0,
        public ?Compensation $compensation = null,  // enum: Money | Repair
        public ?string $slotId = null,
        public ?string $rejectionReason = null,
    ) {}
}
```

### События (только те, у которых есть данные; остальные — строки)

```php
final class FormSubmitted { public function __construct(public array $form) {} }

#[Event('party.decision')]                      // a stable name for webhooks
final class PartyDecision { public function __construct(public bool $approved, public ?string $reason = null) {} }

#[Event('party.documents-requested')]
final class DocumentsRequested { public function __construct(public array $documents) {} }
```

### Процесс

```php
final class ClaimSettlement extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->on('client.cancelled', to: 'cancelling', from: ['application', 'review', 'agreement']);

        $p->state('application', fn (Builder $s) => $s
            ->after('14 days', to: 'abandoned')                      // the whole stage
            ->state('filling', fn (Builder $s) => $s
                ->on(FormSubmitted::class, to: 'signing', run: $this->storeForm(...)))
            ->state('signing', fn (Builder $s) => $s
                ->invoke(RenderApplication::class, into: 'applicationDocId')
                ->on('client.edit', to: 'filling')
                ->on('client.signed', to: 'review')));

        $p->state('review', fn (Builder $s) => $s
            ->correlate([PartyDecision::class, DocumentsRequested::class], by: 'externalId')
            ->state('submitting', fn (Builder $s) => $s
                ->invoke(SubmitToInsurer::class, into: 'externalId', onDone: 'awaitingParty')
                ->defer(PartyDecision::class))
            ->state('awaitingParty', fn (Builder $s) => $s
                ->on(DocumentsRequested::class, to: 'awaitingDocuments', run: $this->countRequest(...))
                ->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...))
                ->on(PartyDecision::class, to: 'rejected', run: $this->storeRejection(...)))
            ->state('awaitingDocuments', fn (Builder $s) => $s
                ->on('client.documents-uploaded', to: 'awaitingParty', run: SendDocuments::class)
                ->after('10 days', to: 'awaitingParty', run: ReportMissingDocuments::class))
            ->final('approved')
            ->onDone(to: 'agreement'));

        $p->parallel('agreement', fn (Builder $s) => $s
            ->region('compensation', fn (Builder $r) => $r
                ->state('offering', fn (Builder $s) => $s
                    ->entry(SendCompensationOffer::class)
                    ->on('client.accepted', to: 'agreed', run: $this->storeCompensation(...))
                    ->on('client.counter-offer', to: 'offering'))    // re-entry sends a new offer
                ->final('agreed'))
            ->region('service', fn (Builder $r) => $r
                ->state('choosingSlot', fn (Builder $s) => $s
                    ->on('client.slot-chosen', to: 'booking', run: $this->storeSlot(...)))
                ->state('booking', fn (Builder $s) => $s
                    ->invoke(BookService::class, onDone: 'booked', onError: 'choosingSlot'))
                ->final('booked'))
            ->onDone(to: 'route'));                                    // join: all

        $p->choice('route')
            ->when($this->isMoney(...), 'payout')
            ->otherwise('finalizing');

        $p->state('payout', fn (Builder $s) => $s
            ->invoke(PayOut::class, onDone: 'finalizing'));

        $p->state('finalizing', fn (Builder $s) => $s
            ->invoke(ArchiveCase::class, onDone: 'closed'));

        $p->state('cancelling', fn (Builder $s) => $s
            ->compensate(onDone: 'cancelled'));                       // undo BookService etc.

        $p->final('closed');
        $p->final('rejected');
        $p->final('abandoned');
        $p->final('cancelled');
    }

    // Guards and context updates: pure, no I/O. Arguments are injected by type.

    private function storeForm(ClaimContext $c, FormSubmitted $e): void { $c->application = $e->form; }
    private function countRequest(ClaimContext $c): void { $c->documentRequests++; }
    private function isApproved(PartyDecision $e): bool { return $e->approved; }
    private function storeRejection(ClaimContext $c, PartyDecision $e): void { $c->rejectionReason = $e->reason; }
    private function storeCompensation(ClaimContext $c, array $e): void { $c->compensation = Compensation::from($e['type']); }
    private function storeSlot(ClaimContext $c, array $e): void { $c->slotId = $e['slotId']; }
    private function isMoney(ClaimContext $c): bool { return $c->compensation === Compensation::Money; }
}
```

~70 строк на процесс с иерархией, циклом дозапросов, параллельным
согласованием, таймаутами, корреляцией, неповторяемыми шагами и
компенсацией. Обвязки в нём нет: каждая строка — решение о процессе.

Тип контекста движок берёт из сигнатур методов (`ClaimContext`) или из
`start()`; объявлять его отдельно не нужно.

### Этапы с побочными эффектами

Обычные классы приложения. Сложность — внутри, движку всё равно.

```php
#[Idempotent]                                       // default: 5 attempts, 10s → 1h, then error
final class RenderApplication
{
    public function __construct(private DocumentRenderer $renderer) {}

    public function __invoke(ClaimContext $claim): string
    {
        return $this->renderer->render('claim-application', $claim->application)->id;
    }
}
```

Неповторяемый шаг со сверкой:

```php
#[AtMostOnce]
final class SubmitToInsurer implements Reconcilable
{
    public function __construct(private InsurerApi $api) {}

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        try {
            return $this->api->submit($claim->application, idempotencyKey: $fx->key)->id;
        } catch (ConnectionRefused $e) {
            throw new NotExecuted($e);                  // never reached the insurer: safe to retry
        }
        // Anything else (timeout, 5xx) is ambiguous: the effect becomes "unknown"
        // and reconcile() runs instead of a retry.
    }

    public function reconcile(ClaimContext $claim, Effect $fx): Outcome
    {
        $found = $this->api->findByIdempotencyKey($fx->key);

        return $found ? Outcome::succeeded($found->id) : Outcome::stillUnknown();
    }
}
```

Если сверка так и не ответила, открывается инцидент. Процесс стоит в
`submitting`, во flow это видно как `unknown`. Оператор решает
`resolveEffect(succeeded: 'APP-123', reason: '…')`, и процесс идёт дальше
по обычному `onDone`.

Неповторяемый и компенсируемый (выплата):

```php
#[AtMostOnce(retry: new Retry(onExhausted: 'incident'))]
final class PayOut implements Reconcilable, Compensable
{
    public function __construct(private Payments $payments) {}

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        return $this->payments->transfer($claim->policyId, $claim->payoutAmount(), key: $fx->key)->id;
    }

    public function reconcile(ClaimContext $claim, Effect $fx): Outcome
    {
        return match ($this->payments->statusByKey($fx->key)) {
            'done'      => Outcome::succeeded($this->payments->idByKey($fx->key)),
            'rejected'  => Outcome::failed('rejected'),
            'not_found' => Outcome::stillUnknown(),     // "not found" may just mean "not visible yet"
        };
    }

    public function compensate(ClaimContext $claim, Effect $fx, string $transferId): void
    {
        $this->payments->refund($transferId, key: $fx->key.':refund'); // compensation is retried until it succeeds
    }
}
```

Сигнатуры `__invoke` / `reconcile` / `compensate` свободные: движок
подставляет по типу контекст, `Effect` (ключ, попытка, id экземпляра) и
результат исходного вызова. Конструктор — обычный DI Laravel.

### Сложный этап как отдельный процесс

Если дозапрос документов сам превратится в процесс (несколько кругов
переписки, свои таймеры), в графе родителя меняется одна строка:

```php
->state('awaitingDocuments', fn (Builder $s) => $s
    ->invoke(DocumentRequest::class, input: $this->requestInput(...), onDone: 'awaitingParty'))
```

`DocumentRequest extends Process` — отдельный класс, своя версия, свой
журнал; во flow это узел с проваливанием.

## 2а. Примитивы для внешних сторон и больших процессов (G1–G9)

Добавлены по итогам проверки API на реальном большом процессе
урегулирования убытков. Всё ниже — сахар над той же
моделью: в канонической форме это обычные состояния и переходы.

```php
// G1. Poll a party that cannot call back. null = nothing yet (no journal entry).
->state('waiting', fn (Builder $s) => $s
    ->poll(CheckSignRequest::class, every: '1 minute')
    ->on(SignCompleted::class, to: 'downloading')
    ->after('24 hours', to: 'expired'))

// G2. A chain of effects: one generated state per step, visible in flow and the journal.
$p->steps('registration', [
    step(AddParticipant::class, forEach: 'participants', into: 'participantIds'),
    step(CreateInsurerClaims::class, into: 'claims'),
    step(AttachDocument::class, forEach: 'documents'),
], onDone: 'settlement');

// G3. forEach over an effect: N calls, join all, results in order; Effect::$item is the element.
->invoke(AttachDocument::class, forEach: 'documents', concurrency: 5, onDone: 'done')

// G4. Route by the child's final state (the builder checks that every final is listed).
->invoke(ESignature::class, input: $this->signingInput(...),
    onDone: ['signed' => 'registration', 'refused' => 'clientRefused', 'expired' => 'cancelled'])

// G5. A routing table instead of a dozen guarded transitions.
->on(ClaimStatusChanged::class, match: 'status', targets: [
    'upload_documents' => 'awaitingDocuments', 'payment' => 'paying', 'done' => 'settled',
])
$p->choice('kind')->match('scenario', ['documents' => 'documents'], otherwise: 'info');

// G6. A child process per event, living as long as the declaring state; deduplicated by key.
$p->on(NotificationReceived::class, spawn: ClaimRequest::class, key: 'messageId');

// G7. One poll fans out to other instances by correlation.
public function __invoke(array $context): array
{
    return array_map(
        fn (array $c) => Publish::to($c['id'], new ClaimStatusChanged($c['status'])),
        $this->insurer->clientClaims($context['clientId']),
    );
}

// G8. Bring a live process from legacy code into the engine, straight into its state.
Processes::adopt(ClaimCase::class, in: ['awaitingDocuments'], context: $ctx,
    key: "claim:{$isn}", by: Actor::operator($user), reason: 'legacy migration');

// G9. What the client sees: the state label and the buttons available now.
->state('agreement', fn (Builder $s) => $s
    ->meta(label: 'Подпишите соглашение')
    ->on('client.agreed', to: 'signingAgreement', label: 'Подписать')
    ->on('client.declined', to: 'reportingRefusal', label: 'Отказаться'))

$view->label();     // 'Подпишите соглашение'
$view->expects();   // [['event' => 'client.agreed', 'label' => 'Подписать'], …]
```

## 2б. Типизированные состояния и привязка к модели

```php
// Enum cases as state ids: IDE completion, typos caught in the editor.
enum ClaimState: string
{
    case Filling = 'filling';
    case Review = 'review';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
}

final class ClaimSettlement extends Process
{
    public const VERSION = 1;
    public const STATES = ClaimState::class;      // optional: the builder checks the enum and the graph match

    public function define(Builder $p): void
    {
        $p->on('client.cancelled', to: ClaimState::Cancelled, from: '*');   // from any unfinished state
        $p->state(ClaimState::Filling, fn (Builder $s) => $s
            ->on(FormSubmitted::class, to: ClaimState::Review));
        // …
    }
}

// Mirror the state into a column of the host model, in the same transaction as the step.
public function projections(): array
{
    return [project(Claim::class, 'status', using: ClaimStatus::class, map: [
        ClaimState::Filling->value => ClaimStatus::Draft,
        ClaimState::Review->value  => ClaimStatus::InReview,
    ])];
}
```

```php
// The host model.
final class Claim extends Model
{
    use HasProcesses;
}

Processes::start(ClaimSettlement::class, new ClaimContext(policyId: $policy->id), subject: $claim);

Claim::whereProcessIn('awaitingDocuments')->paginate();     // indexed, via pe_active_states
$claim->process(ClaimSettlement::class)->expects();         // guards on the context already evaluated
```

Консоль: `php artisan make:process ClaimSettlement`,
`php artisan make:process-effect PayOut --at-most-once`,
`php artisan process-engine:graph ClaimSettlement --format=mermaid`.

## 3. Вызовы из приложения (Laravel)

```php
// Start: the key makes start idempotent (the same key returns the same instance).
$process = Processes::start(ClaimSettlement::class, new ClaimContext(policyId: $policy->id), key: "claim:{$claim->id}");

// Client input from a controller: the form is validated by Laravel as usual.
Processes::send($claim->process_id, new FormSubmitted($request->validated()),
    key: $request->header('Idempotency-Key'), by: $request->user());

// The insurer's webhook: routed by correlation, duplicates dropped by the message id.
Processes::publish(new PartyDecision($payload['approved'], $payload['reason'] ?? null),
    correlate: $payload['application_id'], key: $payload['message_id'], by: Actor::party('insurer'));

// Where is it now?
$view = Processes::find($claim->process_id);
$view->is('awaitingDocuments');        // true
$view->expects();                      // ['client.documents-uploaded', 'after 10 days → 2026-11-05 14:00']
$view->context()->documentRequests;    // 2
$view->journal();                      // transitions, who entered what, why
```

Установка в приложение: `composer require`, `php artisan migrate`, воркер
очереди. Тикер таймеров провайдер добавляет в планировщик сам (нужен только
`schedule:run`, который в приложениях и так есть).

## 4. Тесты процесса

```php
it('settles a repair claim after a document request', function () {
    ProcessTest::start(ClaimSettlement::class, new ClaimContext(policyId: 'P-1'))
        ->fake(RenderApplication::class, returns: 'doc-1')
        ->fake(SubmitToInsurer::class, returns: 'APP-1')
        ->send(new FormSubmitted(['damage' => 'glass']))
        ->send('client.signed')
        ->assertIn('awaitingParty')
        ->publish(new DocumentsRequested(['photo']), correlate: 'APP-1')
        ->travel('10 days')
        ->assertIn('awaitingParty')
        ->assertRan(ReportMissingDocuments::class, times: 1)
        ->publish(new PartyDecision(approved: true), correlate: 'APP-1')
        ->assertIn('offering', 'choosingSlot');
});

it('never resubmits when the worker dies mid-call', function () {
    ProcessTest::start(ClaimSettlement::class, new ClaimContext(policyId: 'P-1'))
        ->fake(SubmitToInsurer::class, returns: 'APP-1', crash: 'after-call')
        ->fake(SubmitToInsurer::class.'@reconcile', returns: Outcome::succeeded('APP-1'))
        ->send(new FormSubmitted([]))->send('client.signed')
        ->recover()                                   // the lease expires, the sweeper runs
        ->assertCalls(SubmitToInsurer::class, 1)
        ->assertIn('awaitingParty');
});
```

`ProcessTest` — in-memory движок с виртуальными часами и инъекцией падений
из ядра (ADR-013); в Laravel-тестах тот же API поверх БД.

## Что пользователь пишет сверх бизнес-логики

| Что | Сколько |
|---|---|
| процесс | `extends Process` + `VERSION` |
| эффект | один атрибут семантики (+ `reconcile`/`compensate`, где это действительно нужно) |
| событие с данными | класс-DTO или массив; `#[Event('name')]` только для внешних имён |
| регистрация, конфиг, провайдер | ничего |
| Laravel | миграции и воркер очереди |

## Сознательные ограничения (цена надёжности)

- Guards и методы изменения контекста **без ввода-вывода и без DI**: класс
  процесса создаётся движком без аргументов. Всё, что ходит наружу, —
  эффект.
- Эффект не меняет контекст напрямую: он возвращает результат (`into:` или
  `onDone` с методом). Иначе изменение контекста оказалось бы вне
  транзакции шага.
- Методы процесса, на которые ссылаются запущенные версии, нельзя удалять и
  менять несовместимо, пока эти версии живы (`process-engine:doctor`
  подскажет). Граф старой версии хранится в БД, удалять из кода можно только
  его.

## Рассмотренные и отвергнутые формы

- **Атрибуты на методах** (`#[On('form.submitted', from: 'filling', to: 'signing')]`).
  Граф размазывается по методам, иерархию и параллельные регионы так
  выразить неудобно, нет общей картины.
- **Строковые имена обработчиков с реестром** (первая редакция ADR-007).
  Лишний шаг регистрации, опечатки ловятся только при запуске.
- **Массив или JSON вручную.** Остаётся канонической формой для хранения и
  будущего визуального редактора, но писать процессы руками в нём неудобно.
