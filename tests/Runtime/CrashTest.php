<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Interpreter\Snapshot;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Runtime\ConcurrencyConflict;
use Dskripchenko\ProcessEngine\Runtime\Steps;
use Dskripchenko\ProcessEngine\Runtime\SyncDispatcher;
use Dskripchenko\ProcessEngine\Runtime\VirtualClock;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\InMemoryStore;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;

dataset('faults', function () {
    foreach (['runner.before_commit', 'runner.after_commit', 'effect.after_claim', 'effect.after_call', 'effect.after_record', 'reconcile.after_call', 'ticker.after_fire'] as $point) {
        foreach ([0, 1, 2, 3, 5, 8] as $skip) {
            yield "{$point} #{$skip}" => [$point, $skip];
        }
    }
});

it('reaches the same end after a crash at any point (approve path)', function (string $point, int $skip) {
    $t = crashScenario($point, $skip, expire: false);
    $t->assertFinal('approved')->assertContext('payment', 'tx-1')->assertContext('uploaded', ['u-a', 'u-b']);
    assertInvariants($t);
})->with('faults');

it('reaches the same end after a crash at any point (timer path)', function (string $point, int $skip) {
    $t = crashScenario($point, $skip, expire: true);
    $t->assertFinal('expired');
    assertInvariants($t);
})->with('faults');

it('never calls an at-most-once effect twice when the worker dies mid-call', function () {
    $t = (new ProcessTest)
        ->fake(Runtime\Charge::class, returns: 'tx-1', crash: 'after-call', reconcile: Outcome::succeeded('tx-1'))
        ->begin(Runtime\Order::class)
        ->send('submit', ['externalId' => 'E', 'files' => []]);
    expect($t->crashes)->toHaveCount(1);
    $t->assertEffect(Runtime\Charge::class, EffectRecord::RUNNING);

    $t->recover()->assertCalls(Runtime\Charge::class, 1)->assertIn('waiting')->assertContext('payment', 'tx-1');
    $types = array_map(fn ($j) => $j->type, $t->view()->journal());
    expect($types)->toContain('effect.lease_expired', 'effect.unknown', 'effect.reconciled');
});

it('repeats an idempotent effect whose worker died mid-call', function () {
    $t = (new ProcessTest)
        ->fake(Runtime\Render::class, returns: 'doc', crash: 'after-call')
        ->begin(Runtime\Order::class)
        ->send('submit', ['externalId' => 'E', 'files' => []])
        ->recover();

    $t->assertCalls(Runtime\Render::class, 2)->assertContext('document', 'doc')->assertIn('waiting');
});

it('lets only one worker step an instance at a time', function () {
    $store = new InMemoryStore;
    $clock = new VirtualClock;
    $a = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'A');
    $b = new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'B');
    $id = $a->start(Runtime\Order::class)->id();
    $a->send($id, 'submit', ['externalId' => 'E', 'files' => []]);

    expect($store->acquireLease($id, 'A', $clock->now()->modify('+2 minutes'), $clock->now()))->toBeTrue();
    expect($b->runInstance($id))->toBeFalse();
    expect($b->get($id)->is('new'))->toBeTrue();

    $clock->advance('3 minutes');
    expect($b->runInstance($id))->toBeTrue();
    expect($b->get($id)->is('rendering'))->toBeTrue();
});

it('fences a zombie worker whose lease expired and whose step is stale', function () {
    $engine = Engine::inMemory();
    $id = $engine->start(Runtime\Order::class)->id();
    $stale = $engine->kernel->store->findInstance($id);

    $engine->send($id, 'submit', ['externalId' => 'E', 'files' => []]);
    $engine->drain();

    $definition = $engine->kernel->definitions->load($stale->definitionId);
    $result = $engine->kernel->interpreter->step($definition, Snapshot::fromArray($stale->snapshot), new Event('cancel'), new StepContext($engine->kernel->now(), $id, $stale->seq + 1));
    $steps = new Steps($engine->kernel);

    expect(fn () => $engine->kernel->tx(fn () => $steps->commit($stale, $definition, $result, null, null, [])))->toThrow(ConcurrencyConflict::class);
    expect($engine->get($id)->is('cancelled'))->toBeFalse();
});

it('keeps two engines on one store consistent under interleaved delivery', function () {
    $store = new InMemoryStore;
    $clock = new VirtualClock;
    $engines = [
        new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'A'),
        new Engine($store, dispatcher: new SyncDispatcher, clock: $clock, worker: 'B'),
    ];
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = $engines[$i % 2]->start(Runtime\Order::class, ['n' => $i])->id();
    }
    foreach ($ids as $i => $id) {
        $engines[0]->send($id, 'submit', ['externalId' => "E{$i}", 'files' => ['x']], key: "s{$i}");
        $engines[1]->send($id, 'submit', ['externalId' => "E{$i}", 'files' => ['x']], key: "s{$i}");
    }
    for ($round = 0; $round < 50; $round++) {
        $progress = $engines[$round % 2]->drain(3) + $engines[($round + 1) % 2]->drain(3);
        $engines[$round % 2]->tick();
        if ($progress === 0 && $round > 5) {
            break;
        }
    }
    foreach ($ids as $i => $id) {
        $view = $engines[0]->get($id);
        expect($view->is('waiting'))->toBeTrue("instance {$i} is in ".implode(',', $view->active()));
        expect(array_filter($view->journal(), fn ($j) => $j->type === 'step' && ($j->data['event']['name'] ?? '') === 'submit'))->toHaveCount(1);
    }
});
