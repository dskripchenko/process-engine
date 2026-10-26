<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Interpreter\Command;
use Dskripchenko\ProcessEngine\Interpreter\Expression;
use Dskripchenko\ProcessEngine\Interpreter\Interpreter;
use Dskripchenko\ProcessEngine\Interpreter\MicrostepLimitExceeded;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StaticFlags;
use Dskripchenko\ProcessEngine\Interpreter\StepResult;
use Dskripchenko\ProcessEngine\Support\Hydrator;
use Dskripchenko\ProcessEngine\Tests\Fixtures;

beforeEach(function () {
    $this->interpreter = new Interpreter;
});

it('enters the first state and moves on events', function () {
    $def = definition(Fixtures\Linear::class);
    $r = $this->interpreter->start($def, [], null, ctx());
    expect($r->snapshot->active)->toBe(['a']);

    $r = $this->interpreter->step($def, $r->snapshot, new Event('go'), ctx());
    expect($r->outcome)->toBe(StepResult::TRANSITIONED)
        ->and($r->snapshot->active)->toBe(['b'])
        ->and($r->exited)->toBe(['a'])
        ->and($r->entered)->toBe(['b']);
});

it('takes the first transition whose guard holds and records rejected candidates', function () {
    $def = definition(Fixtures\Linear::class);
    $s = $this->interpreter->start($def, ['result' => 42], null, ctx())->snapshot;
    $s = $this->interpreter->step($def, $s, new Event('go'), ctx())->snapshot;

    $r = $this->interpreter->step($def, $s, new Event('go'), ctx());
    expect($r->snapshot->status)->toBe(Snapshot::COMPLETED)
        ->and($r->snapshot->final)->toBe('d')
        ->and($r->snapshot->output)->toBe(42)
        ->and($r->rejected[0]['guard'])->toBe('allowed')
        ->and(commandsOf($r->commands, Command::COMPLETE))->toHaveCount(1);

    $s2 = $this->interpreter->start($def, ['allow' => true], null, ctx())->snapshot;
    $s2 = $this->interpreter->step($def, $s2, new Event('go'), ctx())->snapshot;
    expect($this->interpreter->step($def, $s2, new Event('go'), ctx())->snapshot->final)->toBe('c');
});

it('ignores events nobody accepts and lists what is expected', function () {
    $def = definition(Fixtures\Linear::class);
    $s = $this->interpreter->start($def, [], null, ctx())->snapshot;
    $r = $this->interpreter->step($def, $s, new Event('unknown'), ctx());

    expect($r->outcome)->toBe(StepResult::IGNORED)
        ->and($r->expected)->toBe(['go'])
        ->and($r->snapshot->active)->toBe(['a']);
});

it('hydrates typed contexts and events and routes from any state', function () {
    $def = definition(Fixtures\Typed::class);
    $context = Hydrator::dehydrate(new Fixtures\OrderContext(orderId: 'o-1'));
    $s = $this->interpreter->start($def, $context, Fixtures\OrderContext::class, ctx())->snapshot;
    $s = $this->interpreter->step($def, $s, new Event('submit'), ctx())->snapshot;
    expect($s->active)->toBe(['review'])->and($s->context['requests'])->toBe(1);

    $s2 = $this->interpreter->step($def, $s, Event::from(new Fixtures\Decided(false, 'missing photo')), ctx())->snapshot;
    expect($s2->active)->toBe(['draft'])->and($s2->context['reason'])->toBe('missing photo');

    $s3 = $this->interpreter->step($def, $s, Event::from(new Fixtures\Decided(true)), ctx())->snapshot;
    expect($s3->final)->toBe('done');

    $s4 = $this->interpreter->step($def, $s, new Event('cancel'), ctx())->snapshot;
    expect($s4->final)->toBe('cancelled');
});

it('enters and exits compound states in order and fires done.state', function () {
    $def = definition(Fixtures\Nested::class);
    $r = $this->interpreter->start($def, [], null, ctx());
    expect($r->snapshot->active)->toBe(['work', 'one'])
        ->and($r->entered)->toBe(['work', 'one'])
        ->and($r->notes)->toBe(['enter work']);

    $s = $this->interpreter->step($def, $r->snapshot, new Event('next'), ctx())->snapshot;
    $r = $this->interpreter->step($def, $s, new Event('next'), ctx());
    expect($r->snapshot->final)->toBe('closed')
        ->and($r->notes)->toBe(['exit work']);

    $abort = $this->interpreter->step($def, $s, new Event('abort'), ctx());
    expect($abort->exited)->toBe(['two', 'work'])->and($abort->snapshot->final)->toBe('aborted');
});

it('runs parallel regions and joins when all are final', function () {
    $def = definition(Fixtures\Parallel::class);
    $s = $this->interpreter->start($def, ['leftResult' => 'L'], null, ctx())->snapshot;
    expect($s->active)->toBe(['agreement', 'left', 'l1', 'right', 'r1']);

    $s = $this->interpreter->step($def, $s, new Event('left.done'), ctx())->snapshot;
    expect($s->active)->toContain('lf', 'r1')->and($s->status)->toBe(Snapshot::RUNNING);

    $s = $this->interpreter->step($def, $s, new Event('right.done'), ctx())->snapshot;
    expect($s->final)->toBe('done');
});

it('finishes a parallel state on the first region with join any and cancels the others', function () {
    $def = definition(Fixtures\ParallelAny::class);
    $start = $this->interpreter->start($def, [], null, ctx());
    expect(commandsOf($start->commands, Command::SCHEDULE_EFFECT))->toHaveCount(1);

    $r = $this->interpreter->step($def, $start->snapshot, new Event('b.done'), ctx());
    expect($r->snapshot->final)->toBe('won');
    $cancelled = array_map(fn ($c) => $c->data['state'], commandsOf($r->commands, Command::CANCEL_ACTIVATION));
    expect($cancelled)->toContain('a1', 'race');
});

it('routes choices with a match table', function (string $kind, string $final) {
    $def = definition(Fixtures\Choosing::class);
    $s = $this->interpreter->start($def, ['kind' => $kind], null, ctx())->snapshot;
    expect($this->interpreter->step($def, $s, new Event('route'), ctx())->snapshot->final)->toBe($final);
})->with([
    ['money', 'payout'],
    ['repair', 'service'],
    ['other', 'manual'],
]);

it('restores shallow and deep history', function () {
    $def = definition(Fixtures\WithHistory::class);
    $s = $this->interpreter->start($def, [], null, ctx())->snapshot;
    $s = $this->interpreter->step($def, $s, new Event('next'), ctx())->snapshot;
    $s = $this->interpreter->step($def, $s, new Event('deeper'), ctx())->snapshot;
    expect($s->active)->toBe(['work', 'step2', 'inner2']);

    $paused = $this->interpreter->step($def, $s, new Event('pause'), ctx())->snapshot;
    expect($paused->active)->toBe(['paused']);

    $shallow = $this->interpreter->step($def, $paused, new Event('resume'), ctx())->snapshot;
    expect($shallow->active)->toBe(['work', 'step2', 'inner1']);

    $deep = $this->interpreter->step($def, $paused, new Event('resumeDeep'), ctx())->snapshot;
    expect($deep->active)->toBe(['work', 'step2', 'inner2']);
});

it('schedules invocations, timers and stores results into the context', function () {
    $def = definition(Fixtures\Invoking::class);
    $start = $this->interpreter->start($def, ['files' => ['a', 'b']], null, ctx());
    $effects = commandsOf($start->commands, Command::SCHEDULE_EFFECT);
    expect($effects)->toHaveCount(1)
        ->and($effects[0]->data['key'])->toBe('i1/rendering@1/rendering.renderDocument')
        ->and($effects[0]->data['activation'])->toBe('rendering@1');

    $r = $this->interpreter->step($def, $start->snapshot, new Event('done.invoke.rendering.renderDocument', ['activation' => 'rendering@1', 'output' => 'doc-9']), ctx());
    expect($r->snapshot->active)->toBe(['charging'])
        ->and($r->snapshot->context['document'])->toBe('doc-9');
    $timers = commandsOf($r->commands, Command::START_TIMER);
    expect($timers[0]->data['fireAt'])->toBe('2026-10-26T11:00:00.000+00:00')
        ->and($timers[0]->data['event'])->toBe('after.charging.after');

    $unknown = $this->interpreter->step($def, $r->snapshot, new Event('unknown.invoke.charging.chargeCard', ['activation' => 'charging@1']), ctx());
    expect($unknown->snapshot->active)->toBe(['checking']);
});

it('routes errors by code with a fallback', function (string $code, string $state) {
    $def = definition(Fixtures\Invoking::class);
    $s = $this->interpreter->start($def, [], null, ctx())->snapshot;
    $r = $this->interpreter->step($def, $s, new Event('error.invoke.rendering.renderDocument', ['activation' => 'rendering@1', 'code' => $code]), ctx());
    expect($r->snapshot->active === [] ? $r->snapshot->final : $r->snapshot->active[0])->toBe($state);
})->with([['broken', 'failed'], ['timeout', 'retrying']]);

it('discards late results of an earlier activation', function () {
    $def = definition(Fixtures\Invoking::class);
    $s = $this->interpreter->start($def, [], null, ctx())->snapshot;
    $r = $this->interpreter->step($def, $s, new Event('done.invoke.rendering.renderDocument', ['activation' => 'rendering@7', 'output' => 'x']), ctx());

    expect($r->outcome)->toBe(StepResult::DISCARDED)->and($r->snapshot->active)->toBe(['rendering']);
});

it('collects forEach results in order before completing', function () {
    $def = definition(Fixtures\Invoking::class);
    $s = $this->interpreter->start($def, ['files' => ['a', 'b']], null, ctx())->snapshot;
    $s = $this->interpreter->step($def, $s, new Event('done.invoke.rendering.renderDocument', ['activation' => 'rendering@1', 'output' => 'd']), ctx())->snapshot;
    $r = $this->interpreter->step($def, $s, new Event('done.invoke.charging.chargeCard', ['activation' => 'charging@1', 'output' => 'c']), ctx());
    $items = commandsOf($r->commands, Command::SCHEDULE_EFFECT);
    expect($items)->toHaveCount(2)
        ->and($items[1]->data['key'])->toEndWith('uploading.uploadFile#1')
        ->and($items[1]->data['item'])->toBe('b');

    $one = $this->interpreter->step($def, $r->snapshot, new Event('item.invoke.uploading.uploadFile', ['activation' => 'uploading@1', 'index' => 1, 'output' => 'B']), ctx());
    expect($one->outcome)->toBe(StepResult::COLLECTED);
    $two = $this->interpreter->step($def, $one->snapshot, new Event('item.invoke.uploading.uploadFile', ['activation' => 'uploading@1', 'index' => 0, 'output' => 'A']), ctx());
    expect($two->snapshot->final)->toBe('done')
        ->and($two->snapshot->output)->toBe(['document' => 'd', 'uploaded' => ['A', 'B']]);
});

it('completes an empty forEach at once', function () {
    $def = definition(Fixtures\Invoking::class);
    $s = $this->interpreter->start($def, ['files' => []], null, ctx())->snapshot;
    $s = $this->interpreter->step($def, $s, new Event('done.invoke.rendering.renderDocument', ['activation' => 'rendering@1', 'output' => 'd']), ctx())->snapshot;
    $r = $this->interpreter->step($def, $s, new Event('done.invoke.charging.chargeCard', ['activation' => 'charging@1']), ctx());
    expect($r->snapshot->final)->toBe('done');
});

it('starts polls, child processes, spawns and step chains', function () {
    $def = definition(Fixtures\Application::class);
    $start = $this->interpreter->start($def, ['files' => ['x']], null, ctx());
    $child = commandsOf($start->commands, Command::START_CHILD)[0];
    expect($child->data['process'])->toBe(Fixtures\Signing::class)
        ->and($child->data['input'])->toBe(['files' => ['x']]);

    $spawned = $this->interpreter->step($def, $start->snapshot, new Event('request', ['id' => 'm-5']), ctx());
    $spawn = commandsOf($spawned->commands, Command::START_CHILD)[0];
    expect($spawn->data['key'])->toBe('i1/#root@1/spawn/#root.spawn.signing/m-5')
        ->and($spawned->snapshot->active)->toBe(['signing']);

    $r = $this->interpreter->step($def, $start->snapshot, new Event('done.invoke.signing.signing', ['activation' => 'signing@1', 'final' => 'signed']), ctx());
    expect($r->snapshot->active)->toBe(['registered', 'registered_renderDocument']);

    $signing = definition(Fixtures\Signing::class);
    $poll = commandsOf($this->interpreter->start($signing, [], null, ctx())->commands, Command::START_POLL)[0];
    expect($poll->data['nextAt'])->toBe('2026-10-26T10:01:00.000+00:00');
});

it('rejects eventless loops past the limit', function () {
    $def = definition(Fixtures\Looping::class);
    $this->interpreter->start($def, [], null, ctx());
})->throws(MicrostepLimitExceeded::class);

it('defers events and subscribes to correlated ones', function () {
    $def = definition(Fixtures\Deferring::class);
    $s = $this->interpreter->start($def, ['externalId' => 'APP-1'], null, ctx())->snapshot;
    expect($this->interpreter->step($def, $s, new Event('decision', ['ok' => true]), ctx())->outcome)->toBe(StepResult::DEFERRED);

    $r = $this->interpreter->step($def, $s, new Event('submitted'), ctx());
    $sub = commandsOf($r->commands, Command::SUBSCRIBE)[0];
    expect($sub->data['correlationKey'])->toBe('APP-1')->and($sub->data['events'])->toBe(['decision']);
});

it('evaluates guards on the context when listing expected events', function () {
    $def = definition(Fixtures\Expecting::class);
    $s = $this->interpreter->start($def, ['premium' => false], null, ctx())->snapshot;
    $expected = $this->interpreter->expects($def, $s, ctx());
    $events = array_map(fn ($e) => [$e->event, $e->label, $e->conditional], $expected);

    expect($events)->toBe([
        ['client.agreed', 'Sign', false],
        ['client.declined', 'Decline', false],
        ['client.amount', 'Amount', true],
    ]);
});

it('computes timer moments from paths, business days and methods', function () {
    $def = definition(Fixtures\Timing::class);
    $r = $this->interpreter->start($def, ['deadline' => '2026-11-01T00:00:00+00:00', 'vip' => true], null, ctx('2026-10-30T10:00:00+00:00'));
    $at = array_map(fn ($c) => $c->data['fireAt'], commandsOf($r->commands, Command::START_TIMER));

    expect($at)->toBe([
        '2026-11-01T00:00:00.000+00:00',
        '2026-11-03T10:00:00.000+00:00',
        '2026-10-30T11:00:00.000+00:00',
    ]);
});

it('moves an instance by hand, adopts and cancels', function () {
    $def = definition(Fixtures\Nested::class);
    $s = $this->interpreter->start($def, [], null, ctx())->snapshot;
    $moved = $this->interpreter->transitionTo($def, $s, ['two'], ctx());
    expect($moved->snapshot->active)->toBe(['work', 'two'])->and($moved->notes)->toBe(['exit work', 'enter work']);

    $adopted = $this->interpreter->adopt($def, ['two'], ['x' => 1], null, ctx());
    expect($adopted->snapshot->active)->toBe(['work', 'two'])->and($adopted->notes)->toBe([]);

    $cancelled = $this->interpreter->cancel($def, $s, ctx());
    expect($cancelled->snapshot->status)->toBe(Snapshot::CANCELLED)->and($cancelled->notes)->toBe([]);
});

it('reads flags in predicates', function () {
    $flags = new StaticFlags(['fast' => true]);
    expect(Expression::test(['flag' => 'fast'], [], $flags))->toBeTrue()
        ->and(Expression::test(['all' => [['flag' => 'fast'], ['eq' => ['$.context.a', 1]]]], ['context' => ['a' => 1]], $flags))->toBeTrue();
});
