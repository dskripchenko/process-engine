# Events, timers and polling

## Sending an event to an instance

```php
$engine->send($instanceId, new FormSubmitted($form), key: $requestId, by: Actor::user($userId));
$engine->send($instanceId, 'client.signed', ['at' => '2026-10-26'], key: 'sig-881');
```

- An event is an object (its public properties become the payload; the name
  is its class, or `#[Event('name')]`) or a name with an array.
- **The key makes delivery idempotent.** Send the same key twice and the
  second call returns `Receipt::DUPLICATE`. Without a key a random one is
  used, which protects nothing — pass a key for anything that can be retried
  (HTTP requests, webhooks, queue messages).
- `by:` records who did it in the journal.
- An event the current state does not accept is **ignored** and the journal
  says why and what was expected. Asynchronous parties should not lose
  answers to timing, so this is not an error for the sender.

`sendStrict()` checks first and returns `Receipt::REJECTED` with the expected
events, for interfaces that want to say "the form was already submitted".

### Validating events

An event class implementing `SelfValidating` checks itself before it is
queued; the sender gets the exception, the process never sees bad data:

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

`$engine->addValidator(fn (Event $e) => ...)` adds a check for every event.

## Publishing by correlation key

When the other side knows only its own reference (an application number), a
state subscribes under that key:

```php
->state('awaitingParty', fn (Builder $s) => $s
    ->correlate([PartyDecision::class, DocumentsRequested::class], by: 'externalId')
    ->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...)))
```

```php
$engine->publish(new PartyDecision(approved: true), correlate: 'APP-123', key: $webhookMessageId);
```

- The subscription is created when the state is entered, from the context
  property `by:`, and removed when it is left. Subscribe where the key is
  already known.
- A published event nobody waits for yet is **buffered** (7 days by default)
  and delivered as soon as an instance subscribes: an answer that arrives
  before the process started waiting is not lost.
- Duplicates (the same event, key and correlation key) are dropped.

## Task tokens

A callback addressed to the current activation of a state:

```php
$token = $engine->taskToken($instanceId, 'awaitingSignature');   // put it into a callback URL
// later, in the callback handler:
$engine->complete($token, 'signature.completed', $payload);
```

The token is signed (HMAC with the engine secret). A forged token throws; a
token of a finished activation returns `Receipt::REJECTED`.

## Deferring

```php
->state('submitting', fn (Builder $s) => $s->defer(PartyDecision::class))
```

A deferred event stays in the inbox until a state that accepts it is active
(30 days by default), instead of being ignored.

## Timers

```php
->after('10 days', to: 'reminded')                    // from entering the state
->after('PT2H', run: RemindClient::class)              // ISO 8601; no target: stay
->after($this->slaFor(...), to: 'escalated')           // a method returning a duration or a moment
->after(businessDays(5), to: 'late')                   // through a BusinessCalendar
->at('deadline', to: 'expired')                        // a context property with an ISO 8601 date
```

- A timer belongs to one activation of its state: leaving the state cancels
  it; re-entering starts a new one. A timer that fires after the state was
  left is discarded.
- Firing is idempotent (a timer key), so a double tick does nothing.
- Durations: `14 days`, `1 day, 2 hours`, `10s`, `2h`, `P14D`, `PT10M`.
- Business days use `BusinessCalendar` (default: Monday to Friday). The
  engine knows no holidays; pass your calendar to the engine.
- Precision is the tick's: run `tick()` every minute (a scheduler), or a
  worker loop for seconds. Timers are for business deadlines, not milliseconds.

## Polling

For parties that cannot call back:

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

- The handler must be `#[Idempotent]`.
- `null` means "nothing yet": no event and **no journal entry**, only counters.
  A poll every minute for a day costs nothing in the journal.
- An event (object or name), or a list of events, is delivered to the instance.
- `every:` may be a method of the process, re-evaluated before each run: poll
  every 5 minutes while the client is online and hourly otherwise.
- Failures follow the handler's retry policy; when it runs out, an incident is
  opened and polling continues at the maximum interval.
- An event can trigger an extra run: `->on('client.opened-page', run: CheckSignature::class)`.

### Fanning out

A poll (or any effect) can address other instances:

```php
public function __invoke(array $context): array
{
    return array_map(
        fn (array $claim) => Publish::to($claim['id'], new ClaimStatusChanged($claim['status'])),
        $this->insurer->clientClaims($context['clientId']),
    );
}
```

`Publish::to($correlationKey, $event)` goes through subscriptions and the
buffer; `Send::to($instanceId, $event)` goes straight to an inbox. One request
for all claims of a client is far cheaper than a poll per claim.
