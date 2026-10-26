<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Effect\EffectFailed;
use Dskripchenko\ProcessEngine\Effect\EffectNotExecuted;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Runtime\Receipt;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;

function order(): ProcessTest
{
    return (new ProcessTest)->begin(Runtime\Order::class, ['n' => 7]);
}

it('runs a process end to end through effects, forEach and correlation', function () {
    $t = order()
        ->assertIn('new')
        ->assertRan(Runtime\Notify::class)
        ->send('submit', ['externalId' => 'EXT-1', 'files' => ['a', 'b']])
        ->assertIn('waiting')
        ->assertContext('document', 'doc-7')
        ->assertContext('payment', 'tx-1')
        ->assertContext('uploaded', ['u-a', 'u-b']);

    $t->publish('party.decision', 'EXT-1', ['approved' => true], key: 'm-1')->assertFinal('approved');

    $types = array_map(fn ($j) => $j->type, $t->view()->journal());
    expect($types)->toContain('started', 'step', 'effect.succeeded', 'completed');
});

it('starts idempotently by key and ignores duplicate events', function () {
    $t = new ProcessTest;
    $a = $t->engine->start(Runtime\Order::class, [], key: 'order-1');
    $b = $t->engine->start(Runtime\Order::class, [], key: 'order-1');
    expect($a->id())->toBe($b->id());

    $first = $t->engine->send($a->id(), 'submit', ['externalId' => 'E'], key: 'k-1');
    $again = $t->engine->send($a->id(), 'submit', ['externalId' => 'E'], key: 'k-1');
    expect($first->status)->toBe(Receipt::ACCEPTED)->and($again->status)->toBe(Receipt::DUPLICATE);
});

it('buffers a published event until the instance subscribes', function () {
    $t = order();
    $receipt = $t->engine->publish('party.decision', 'EXT-9', ['approved' => false], key: 'early');
    expect($receipt->status)->toBe(Receipt::BUFFERED);

    $t->send('submit', ['externalId' => 'EXT-9'])->assertFinal('rejected');
});

it('retries an idempotent effect with backoff, then succeeds', function () {
    $t = (new ProcessTest)
        ->fake(Runtime\Render::class, sequence: [new RuntimeException('timeout'), new RuntimeException('timeout'), 'doc-x'])
        ->begin(Runtime\Order::class)
        ->send('submit', ['externalId' => 'E'])
        ->assertIn('rendering');

    $t->travel('5 minutes', '5 seconds')->assertIn('waiting')->assertContext('document', 'doc-x')->assertCalls(Runtime\Render::class, 3);
});

it('routes an exhausted idempotent effect to its error transition', function () {
    order()
        ->fake(Runtime\Render::class, throws: new RuntimeException('down'))
        ->send('submit', ['externalId' => 'E'])
        ->travel('10 minutes', '5 seconds')
        ->assertFinal('renderFailed')
        ->assertCalls(Runtime\Render::class, 3);
});

it('does not retry a business failure', function () {
    order()
        ->fake(Runtime\Render::class, throws: new EffectFailed('bad_template'))
        ->send('submit', ['externalId' => 'E'])
        ->assertFinal('renderFailed')
        ->assertCalls(Runtime\Render::class, 1);
});

it('retries an at-most-once effect only when it certainly did not run', function () {
    order()
        ->fake(Runtime\Charge::class, sequence: [new EffectNotExecuted('connection refused'), 'tx-2'])
        ->send('submit', ['externalId' => 'E'])
        ->travel('1 minute', '5 seconds')
        ->assertContext('payment', 'tx-2')
        ->assertCalls(Runtime\Charge::class, 2);
});

it('marks an ambiguous at-most-once failure unknown and reconciles it', function () {
    $t = order()
        ->fake(Runtime\Charge::class, throws: new RuntimeException('read timeout'), reconcile: Outcome::succeeded('tx-found'))
        ->send('submit', ['externalId' => 'E']);

    $t->assertCalls(Runtime\Charge::class, 1)->assertContext('payment', 'tx-found')->assertIn('waiting');
    expect(count($t->fakeOf(Runtime\Charge::class)->reconciles))->toBe(1);
});

it('raises an incident when reconciliation cannot tell, and continues after an operator decision', function () {
    $t = order()
        ->fake(Runtime\Charge::class, throws: new RuntimeException('read timeout'), reconcile: Outcome::stillUnknown())
        ->send('submit', ['externalId' => 'E'])
        ->travel('3 days', '30 minutes');

    $t->assertIn('paying')->assertEffect(Runtime\Charge::class, EffectRecord::UNKNOWN)->assertIncident(IncidentRecord::EFFECT_UNKNOWN)->assertCalls(Runtime\Charge::class, 1);

    $t->resolveEffect(Runtime\Charge::class, true, 'tx-by-hand')->assertContext('payment', 'tx-by-hand')->assertIn('waiting');
});

it('refuses to rerun an unknown at-most-once effect without explicit confirmation', function () {
    $t = (new ProcessTest)
        ->fake(Runtime\Transfer::class, throws: new RuntimeException('timeout'))
        ->begin(Runtime\Payout::class)
        ->assertIncident(IncidentRecord::EFFECT_UNKNOWN);
    $effect = $t->effect(Runtime\Transfer::class);

    $t->engine->retryEffect($effect->id, $t->operator(), 'try again');
    $t->drain()->assertCalls(Runtime\Transfer::class, 1);
    expect(array_map(fn ($j) => $j->type, $t->view()->journal()))->toContain('manual.rejected');

    $t->fake(Runtime\Transfer::class, returns: 'ok');
    $t->engine->retryEffect($effect->id, $t->operator(), 'bank confirmed it did not go through', confirmUnsafe: true);
    $t->drain()->assertFinal('paid');
});

it('fires timers and discards those of states already left', function () {
    $t = order()->send('submit', ['externalId' => 'E'])->assertIn('waiting');
    $t->travel('3 days')->assertFinal('expired');

    $t2 = order()->send('submit', ['externalId' => 'E2'])->publish('party.decision', 'E2', ['approved' => true]);
    $t2->travel('4 days')->assertFinal('approved');
    expect($t2->view()->timers())->toBe([]);
});

it('runs child processes, routes by their final state and collects output', function () {
    $t = (new ProcessTest)->begin(Runtime\Claim::class, ['claimId' => 'C-1']);
    $children = $t->view()->children();
    expect($children)->toHaveCount(1);

    $t->engine->send($children[0]->id, 'signed', ['files' => ['s.pdf']]);
    $t->drain()->assertIn('review')->assertContext('signature', ['s.pdf']);
});

it('cancels children when the parent leaves their state', function () {
    $t = (new ProcessTest)->begin(Runtime\Claim::class, ['claimId' => 'C-1']);
    $child = $t->view()->children()[0];
    $t->engine->transitionTo($t->id(), ['paid'], $t->operator(), 'skip');
    $t->drain();

    expect($t->engine->get($child->id)->status())->toBe('cancelled');
});

it('spawns one child per event key', function () {
    $t = (new ProcessTest)->begin(Runtime\Claim::class, ['claimId' => 'C-1'])
        ->send('request', ['id' => 'r1'])
        ->send('request', ['id' => 'r1'], key: 'other-key')
        ->send('request', ['id' => 'r2']);

    expect($t->view()->children())->toHaveCount(3);
});

it('polls quietly until there is news, and fans results out by correlation', function () {
    $t = (new ProcessTest)->begin(Runtime\Claim::class, ['claimId' => 'C-1']);
    $child = $t->view()->children()[0];
    $t->travel('30 minutes', '1 minute');
    expect(array_filter($t->engine->get($child->id)->journal(), fn ($j) => str_starts_with($j->type, 'poll')))->toBe([]);

    $t->engine->send($child->id, 'signed', ['files' => []]);
    $t->drain()->assertIn('review')->travel('2 hours', '30 minutes')->assertFinal('paid');
});

it('compensates completed compensable effects in reverse order on cancel', function () {
    $t = order()->send('submit', ['externalId' => 'E', 'files' => []])->assertIn('waiting');
    $t->fake(Runtime\Charge::class)->fake(Runtime\Book::class);
    $t->send('cancel')->assertFinal('closed');

    $compensated = array_values(array_filter($t->view()->journal(), fn ($j) => $j->type === 'effect.compensated'));
    expect(array_map(fn ($j) => $j->data['class'], $compensated))->toBe([Runtime\Book::class, Runtime\Charge::class]);
});

it('blocks compensation while an effect outcome is unknown', function () {
    $t = order()
        ->fake(Runtime\Charge::class, throws: new RuntimeException('timeout'), reconcile: Outcome::stillUnknown())
        ->send('submit', ['externalId' => 'E'])
        ->send('cancel');

    $t->assertIn('cancelled')->assertIncident(IncidentRecord::COMPENSATION_BLOCKED);
});

it('suspends, queues events, resumes', function () {
    $t = order();
    $t->engine->suspend($t->id(), $t->operator(), 'investigation');
    $t->drain()->send('submit', ['externalId' => 'E'])->assertIn('new')->assertStatus('suspended');

    $t->engine->resume($t->id(), $t->operator(), 'done');
    $t->drain()->assertIn('waiting');
});

it('moves by hand and patches the context with the reason in the journal', function () {
    $t = order();
    $t->engine->patchContext($t->id(), [['op' => 'add', 'path' => '/externalId', 'value' => 'X-1']], Actor::operator('ann'), 'typo');
    $t->engine->transitionTo($t->id(), ['waiting'], Actor::operator('ann'), 'skip to waiting');
    $t->drain()->assertIn('waiting')->assertContext('externalId', 'X-1');

    $manual = array_values(array_filter($t->view()->journal(), fn ($j) => str_starts_with($j->type, 'manual.')));
    expect(array_map(fn ($j) => [$j->type, $j->reason, $j->actor['id']], $manual))->toBe([
        ['manual.patch', 'typo', 'ann'],
        ['manual.transition', 'skip to waiting', 'ann'],
    ]);
});

it('requires a reason for operator actions', function () {
    $t = order();
    $t->engine->suspend($t->id(), $t->operator(), ' ');
})->throws(InvalidArgumentException::class, 'reason');

it('delivers events through task tokens of the current activation only', function () {
    $t = order()->send('submit', ['externalId' => 'E']);
    $token = $t->engine->taskToken($t->id(), 'waiting');
    expect($t->engine->complete($token, 'party.decision', ['approved' => true])->status)->toBe(Receipt::ACCEPTED);
    $t->drain()->assertFinal('approved');
    expect($t->engine->complete($token, 'party.decision', ['approved' => true])->status)->toBe(Receipt::REJECTED);
    expect(fn () => $t->engine->complete($token.'x', 'party.decision'))->toThrow(InvalidArgumentException::class);
});

it('rejects strict sends the configuration does not accept', function () {
    $t = order();
    $receipt = $t->engine->sendStrict($t->id(), 'party.decision');
    expect($receipt->status)->toBe(Receipt::REJECTED)->and($receipt->expected)->toBe(['submit', 'cancel']);
});

it('archives finished instances and still reads them', function () {
    $t = order()->send('cancel')->assertFinal('closed');
    $t->travel('31 days', '1 day');
    expect($t->engine->archive())->toBe(1);

    $view = $t->engine->get($t->id());
    expect($view->isArchived())->toBeTrue()->and($view->final())->toBe('closed')->and(count($view->journal()))->toBeGreaterThan(3);
});

it('adopts an instance straight into a state', function () {
    $t = new ProcessTest;
    $view = $t->engine->adopt(Runtime\Order::class, ['waiting'], ['externalId' => 'L-1'], key: 'legacy-1', by: $t->operator(), reason: 'migration');
    $t->drain();
    expect($view->active())->toBe(['waiting'])->and($t->engine->get($view->id())->timers())->toHaveCount(1);

    $t->engine->publish('party.decision', 'L-1', ['approved' => true]);
    $t->drain();
    expect($t->engine->get($view->id())->final())->toBe('approved');
});

it('does not start effects of a suspended instance until it resumes', function () {
    $t = (new ProcessTest)->begin(Runtime\Order::class);
    $t->engine->suspend($t->id(), $t->operator(), 'hold');
    $t->drain();
    $t->engine->operate($t->id(), 'transition', ['states' => ['rendering']], $t->operator(), 'force while suspended');
    $t->drain()->travel('5 minutes', '1 minute');
    expect($t->effect(Runtime\Render::class)->status)->toBe(EffectRecord::PENDING);

    $t->engine->resume($t->id(), $t->operator(), 'go');
    $t->drain()->travel('2 minutes', '1 minute')->assertNotIn('rendering');
});
