<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Builder\Compiler;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\Validator;
use Dskripchenko\ProcessEngine\Effect\EffectFailed;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Export\FlowExporter;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Deferred;

// ADR-017: what 1.0 left for later.

it('finishes a parallel state once the quorum of regions is done, leaving the rest', function () {
    $t = (new ProcessTest)->begin(Deferred\Vote::class);
    $t->send('a.yes');
    expect($t->view()->is('voting'))->toBeTrue();
    $t->send('c.yes')->assertFinal('passed');
    expect($t->view()->contextArray()['regions'])->toBe(['a', 'c']);
    // A late vote changes nothing.
    $t->send('b.yes')->assertFinal('passed');
});

it('finishes a forEach invocation once its quorum of calls succeeds, the number read from a method', function () {
    $t = (new ProcessTest)
        ->fake(Deferred\Ask::class, using: fn ($fx) => $fx->item === 'bob' ? throw new EffectFailed('declined') : 'yes-'.$fx->item)
        ->begin(Deferred\Poll::class, ['people' => ['ann', 'bob', 'cid', 'dan']]);
    $t->drain()->assertFinal('agreed');
    // A majority of 4 is 3: ann, cid and dan in their order; bob's failure was absorbed.
    expect($t->view()->output())->toBe(['yes-ann', 'yes-cid', 'yes-dan']);
    expect($t->view()->incidents())->toBe([]);
});

it('fails a forEach invocation with quorum_unreachable when too many calls fail', function () {
    $t = (new ProcessTest)
        ->fake(Deferred\Ask::class, using: fn ($fx) => in_array($fx->item, ['bob', 'cid'], true) ? throw new EffectFailed('declined') : 'yes')
        ->begin(Deferred\Poll::class, ['people' => ['ann', 'bob', 'cid']]);
    $t->drain()->assertFinal('failed');
    $errors = array_values(array_map(fn ($j) => $j->type, array_filter($t->view()->journal(), fn ($j) => str_starts_with($j->data['event']['name'] ?? '', 'error.invoke.'))));
    // The first failure was absorbed (2 of 3 could still agree), the second made the quorum unreachable.
    expect($errors)->toBe(['event.collected', 'step'])->and($t->view()->incidents())->toBe([]);
});

it('finishes a forEach invocation over no elements at once, quorum or not', function () {
    (new ProcessTest)->begin(Deferred\Poll::class, ['people' => []])->assertFinal('agreed');
});

it('refuses a quorum larger than the regions, or of an invocation without forEach', function () {
    $data = Compiler::compile(Deferred\Vote::class)->toArray();
    $data['root']['states'][0]['join'] = ['quorum' => 4];
    expect(array_map(fn ($i) => $i->code, Validator::validate($data)))->toContain('quorum');

    $data = Compiler::compile(Deferred\Poll::class)->toArray();
    unset($data['root']['states'][0]['invoke'][0]['forEach']);
    expect(array_map(fn ($i) => $i->code, Validator::validate($data)))->toContain('quorum');
});

it('keeps the canonical form of definitions without a quorum', function () {
    // all / any stay strings: stored definitions keep their hash.
    $data = Compiler::compile(Deferred\Vote::class)->toArray();
    expect($data['root']['states'][0]['join'])->toBe(['quorum' => 2]);
    expect(Definition::fromArray(array_replace_recursive($data, ['root' => ['states' => [['join' => 'any']]]]))->node('voting')->join)->toBe('any');
});

it('shows the quorum on the flow diagram', function () {
    $graph = (new FlowExporter)->graph(Compiler::compile(Deferred\Vote::class));
    $voting = array_values(array_filter($graph['nodes'], fn ($n) => $n['id'] === 'voting'))[0];
    expect($voting['data'])->toBe(['kind' => 'parallel', 'join' => 'quorum', 'quorum' => '2 of 3']);

    $graph = (new FlowExporter)->graph(Compiler::compile(Deferred\Poll::class));
    $asking = array_values(array_filter($graph['nodes'], fn ($n) => $n['id'] === 'asking'))[0];
    expect($asking['data']['quorum'])->toBe('majority()');
});

it('undoes an effect whose result comes after its state was left', function () {
    $t = (new ProcessTest)
        ->fake(Deferred\Pay::class, sequence: [new RuntimeException('timeout')], reconcile: Outcome::stillUnknown())
        ->begin(Deferred\Checkout::class);
    $t->drain();
    expect($t->effect(Deferred\Pay::class)->status)->toBe(EffectRecord::UNKNOWN);
    $t->send('giveUp')->assertFinal('abandoned');
    $t->fakeOf(Deferred\Pay::class)->reconcile = Outcome::succeeded('tx-9');
    // The reconciliation finds the payment went through; nobody waits for it: it is refunded.
    $t->travel('1 hour', '1 minute');
    expect($t->fakeOf(Deferred\Pay::class)->compensations)->toHaveCount(1);
    expect($t->effect(Deferred\Pay::class)->status)->toBe(EffectRecord::COMPENSATED);
    $types = array_map(fn ($j) => $j->type, $t->view()->journal());
    expect($types)->toContain('effect.orphan_compensating')->toContain('effect.orphan_compensated');
    expect($t->view()->incidents())->toBe([]);
});

it('cancels a child with its compensations when its state is left', function () {
    $t = (new ProcessTest)->fake(Deferred\Ask::class, returns: 'held')->begin(Deferred\Trip::class);
    $child = $t->view()->children()[0];
    expect($t->engine->get($child->id)->is('held'))->toBeTrue();
    $t->send('cancel')->assertFinal('cancelled');
    $t->drain();
    expect($t->engine->get($child->id)->status())->toBe('cancelled');
    expect($t->fakeOf(Deferred\Ask::class)->compensations)->toHaveCount(1);
});

it('leaves late results alone by default', function () {
    $t = (new ProcessTest)
        ->fake(Deferred\Pay::class, sequence: [new RuntimeException('timeout')], reconcile: Outcome::succeeded('tx-9'));
    $data = Compiler::compile(Deferred\Checkout::class)->toArray();
    unset($data['root']['states'][0]['invoke'][0]['onOrphan']);
    expect(Definition::fromArray($data)->orphanPolicy($data['root']['states'][0]['invoke'][0]))->toBe('log');
});

it('refuses onOrphan: compensate on an effect that cannot be undone', function () {
    $data = Compiler::compile(Deferred\Checkout::class)->toArray();
    $data['root']['states'][0]['invoke'][0]['effect'] = Deferred\Plain::class;
    expect(array_map(fn ($i) => $i->code, Validator::validate($data)))->toContain('on-orphan');
});

it('counts effects of unknown outcome and work the workers left standing', function () {
    $t = (new ProcessTest)
        ->fake(Deferred\Pay::class, sequence: [new RuntimeException('timeout')], reconcile: Outcome::stillUnknown())
        ->begin(Deferred\Checkout::class);
    $t->drain();
    $health = $t->engine->health();
    expect($health)->toHaveKeys(['unknown_effects', 'stale_inbox', 'stale_outbox'])
        ->and($health['unknown_effects'])->toBe(0);
    $t->clock->advance('20 minutes');
    expect($t->engine->health()['unknown_effects'])->toBe(1);

    // An event nobody processed for ten minutes.
    $t->engine->send($t->id(), 'giveUp');
    $t->clock->advance('10 minutes');
    expect($t->engine->health()['stale_inbox'])->toBe(1);
});
