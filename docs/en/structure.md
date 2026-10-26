# Structure: nesting, parallel regions, history, child processes

Two kinds of structure, with different costs: inside one instance
(compound and parallel states) and across instances (child processes).

## Compound states

```php
$p->state('application', fn (Builder $s) => $s
    ->after('14 days', to: 'abandoned')              // applies to every state inside
    ->on('client.cancelled', to: 'cancelled')        // so does this
    ->state('filling', fn (Builder $s) => $s->on(FormSubmitted::class, to: 'signing'))
    ->state('signing', fn (Builder $s) => $s->on('client.signed', to: 'signed'))
    ->final('signed')
    ->onDone(to: 'review'));
```

- Children share the context and the journal.
- Transitions declared on the compound state work from any state inside it:
  a timer on `application` is a deadline for the whole stage.
- Reaching a final child raises `done.state.<id>`; `onDone()` reacts to it.

## Parallel states

```php
$p->parallel('agreement', fn (Builder $s) => $s
    ->region('compensation', fn (Builder $r) => $r
        ->state('offering', fn (Builder $s) => $s->on('client.accepted', to: 'agreed'))
        ->final('agreed', output: 'compensation'))
    ->region('service', fn (Builder $r) => $r
        ->state('choosingSlot', fn (Builder $s) => $s->on('client.slot-chosen', to: 'booked'))
        ->final('booked'))
    ->onDone(to: 'route'), join: 'all');
```

- `join: 'all'` (default): done when every region reached its final state;
  the `done` event carries each region's output.
- `join: 'any'`: done when the first region finishes; the other regions are
  exited, their pending effects and child processes cancelled.
- Regions are logically parallel and physically sequential (one lease per
  instance); their effects run truly in parallel.

## History

```php
$p->state('work', fn (Builder $s) => $s
    ->on('pause', to: 'paused')
    ->state('step1', /* … */)
    ->state('step2', /* … */)
    ->history('resume')                 // shallow: the last active child
    ->history('resumeDeep', deep: true)); // deep: the exact nested states
$p->state('paused', fn (Builder $s) => $s->on('continue', to: 'resume'));
```

Entering a history state restores the configuration the parent had when it
was last exited (or the parent's initial state the first time, or `default:`).

## Child processes

A state can run another process as a separate instance:

```php
->invoke(DocumentRequest::class,
    input: $this->requestInput(...),
    onDone: ['signed' => 'review', 'refused' => 'refused', 'expired' => 'refused'],
    onError: 'failed')
```

- The child has its own version (fixed when it starts), context, journal
  and lease. It sees only its `input`; the parent sees only its `output`
  (`final('signed', output: 'files')`).
- `onDone` with a map routes by the child's final state; the builder checks
  that every final of the child is routed.
- If the parent leaves the state, the child is cancelled. A failed or
  cancelled child sends `error.invoke.<id>` with code `child_failed` /
  `child_cancelled`.

### One child per element

```php
->invoke(SignDocument::class, forEach: 'documents', into: 'signatures', onDone: 'done')
```

N children, results collected in the order of the list, done when all are.

### One child per event: `spawn`

```php
$p->on(NotificationReceived::class, spawn: ClaimRequest::class, input: $this->requestInput(...), key: 'messageId');
```

- A child starts for each event, without a transition of the parent.
- `key` (an event field) deduplicates: the same value never starts a second child.
- The child lives as long as the scope where `spawn` is declared (a state,
  or the whole process); `onScopeExit: 'keep'` lets it outlive the scope.
- Its completion arrives as `done.spawn.<id>`; reacting is optional.
  `StepContext::spawned()` counts the live ones for guards.

## Which to use

| Need | Use |
|---|---|
| stages of one process, shared context, "from anywhere in the stage" | compound state |
| branches running at the same time that join | parallel state |
| a reusable piece with its own version and life cycle | child process |
| N similar branches from data | `invoke(..., forEach:)` of a child process |
| N similar external calls from data | `invoke(..., forEach:)` of an effect |
| sub-processes arriving as events, unknown in number | `spawn` |
