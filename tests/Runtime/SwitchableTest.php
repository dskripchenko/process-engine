<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Export\FlowExporter;
use Dskripchenko\ProcessEngine\Interpreter\StaticFlags;
use Dskripchenko\ProcessEngine\Store\BatchRecord;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches\Script;

beforeEach(fn () => Script::reset());

it('runs a switchable stage while its switch is on', function () {
    $t = (new ProcessTest)->begin(Batches\Pipeline::class, ['n' => 12])->assertIn('merge');

    expect(Script::seen())->toHaveCount(12)->and($t->view()->contextArray())->not->toHaveKey('skipped');
});

it('skips a stage switched off, before it invokes anything, and notes why', function () {
    $t = new ProcessTest;
    $t->engine->switchOff('enrichment.dadata', $t->operator(), 'no key yet');
    $t->begin(Batches\Pipeline::class, ['n' => 12])->assertIn('merge');

    expect(Script::$calls)->toBe([])->and($t->engine->batches($t->id()))->toBe([])
        ->and($t->view()->contextArray()['skipped'])->toBe([['state' => 'dadata', 'switch' => 'enrichment.dadata', 'reason' => 'off']])
        ->and($t->engine->switchState('enrichment.dadata'))->toMatchArray(['on' => false, 'reason' => 'no key yet']);

    $t->engine->switchOn('enrichment.dadata', $t->operator());
    $again = $t->engine->start(Batches\Pipeline::class, ['n' => 3]);
    $t->drain();
    expect($t->view($again->id())->active())->toBe(['merge'])->and(Script::seen())->toHaveCount(3);
});

it('skips a stage switched off for one run only', function () {
    $t = (new ProcessTest)->begin(Batches\Pipeline::class, ['n' => 12, 'disabled' => ['enrichment.dadata']])->assertIn('merge');

    expect(Script::$calls)->toBe([])
        ->and($t->view()->contextArray()['skipped'][0]['reason'])->toBe('off for this run')
        ->and($t->engine->switchState('enrichment.dadata')['on'])->toBeTrue();
});

it('switches a stage off by itself on a fatal error, and later runs skip it', function () {
    Script::$fatal = '5';
    $t = (new ProcessTest)->begin(Batches\Pipeline::class, ['n' => 12])->assertIn('merge');

    expect($t->engine->switchState('enrichment.dadata'))->toMatchArray(['on' => false, 'reason' => 'the key was revoked'])
        ->and($t->engine->switchState('enrichment.dadata')['by']['type'])->toBe('system');
    $types = array_map(fn ($j) => $j->type, $t->view()->journal());
    expect($types)->toContain('switch.off')
        ->and(array_column($t->signals->signals, 'type'))->toContain('switch.changed');

    Script::$fatal = null;
    $next = $t->engine->start(Batches\Pipeline::class, ['n' => 5]);
    $t->drain();
    expect($t->view($next->id())->active())->toBe(['merge'])
        ->and($t->view($next->id())->contextArray()['skipped'][0]['reason'])->toBe('off');
});

it('takes a running stage out when its switch goes off', function () {
    Script::$blocked = '12';
    $t = (new ProcessTest)->begin(Batches\Pipeline::class, ['n' => 12])->assertIn('dadata');

    $t->engine->switchOff('enrichment.dadata', $t->operator(), 'banned by the service');
    $t->drain()->assertIn('merge');

    $batch = $t->engine->batches($t->id())[0];
    expect($batch->status)->toBe(BatchRecord::CANCELLED)
        ->and($batch->summary()['deferred'])->toBe(2)
        ->and($t->view()->contextArray()['skipped'][0]['reason'])->toBe('switched off while running');
});

it('marks switchable stages on the graph, off when their switch is off', function () {
    $t = new ProcessTest;
    $definition = $t->engine->start(Batches\Pipeline::class, ['n' => 1])->definition();
    $flags = new StaticFlags;

    $node = fn (array $graph) => array_values(array_filter($graph['nodes'], fn ($n) => $n['id'] === 'dadata'))[0];
    $on = $node((new FlowExporter($flags))->graph($definition));
    expect($on['enabled'])->toBeTrue()->and($on['data']['switch'])->toBe('enrichment.dadata')->and($on['data']['batch'])->toBe('Lookup');

    $flags->set('enrichment.dadata', false, $t->operator(), 'test');
    expect($node((new FlowExporter($flags))->graph($definition))['enabled'])->toBeFalse();
});
