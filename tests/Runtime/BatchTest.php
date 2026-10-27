<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Export\FlowExporter;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Store\BatchRecord;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches\Script;

beforeEach(fn () => Script::reset());

function batchOf(ProcessTest $t): BatchRecord
{
    return $t->engine->batches($t->id())[0];
}

it('works through every element in chunks and hands the counters to the process', function () {
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 95])->assertFinal('done');

    expect(Script::seen())->toBe(array_map('strval', range(1, 95)))
        ->and(array_map('count', Script::$calls))->toBe([10, 10, 10, 10, 10, 10, 10, 10, 10, 5])
        ->and($t->view()->contextArray()['counters'])->toBe(['done' => 82, 'not_found' => 13, 'failed' => 0, 'deferred' => 0, 'skipped' => 0, 'total' => 95]);

    $batch = batchOf($t);
    expect($batch->status)->toBe(BatchRecord::DONE)->and($batch->total)->toBe(95)->and($batch->running)->toBe(0)
        ->and($t->engine->batchItems($batch->id, 'not_found', 100))->toHaveCount(13);
    $types = array_map(fn ($j) => $j->type, $t->view()->journal());
    expect($types)->toContain('batch.started', 'batch.done')
        ->and(array_filter($t->view()->effects(), fn (EffectRecord $e) => $e->status !== EffectRecord::SUCCEEDED))->toBe([]);
});

it('finishes at once on an empty source', function () {
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 0])->assertFinal('done');

    expect(Script::$calls)->toBe([])->and($t->view()->contextArray()['counters']['total'])->toBe(0);
});

it('tries a failed element again in a later chunk, then leaves it failed', function () {
    Script::$flaky = ['3' => 1, '4' => 5];
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 25])->assertFinal('done');

    $seen = array_count_values(Script::seen());
    expect($seen['3'])->toBe(2)->and($seen['4'])->toBe(2)
        ->and($t->view()->contextArray()['counters'])->toMatchArray(['done' => 21, 'not_found' => 3, 'failed' => 1]);
    $failed = $t->engine->batchItems(batchOf($t)->id, 'failed');
    expect($failed[0]->key)->toBe('4')->and($failed[0]->attempts)->toBe(2)->and($failed[0]->reason)->toBe('timeout');
});

it('waits while the service is unavailable and goes on when it is back', function () {
    Script::$down = 2;
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 12])->assertIn('lookup');
    expect(Script::$calls)->toBe([]);

    $t->travel('10 minutes', '1 minute')->assertFinal('done');
    expect($t->view()->contextArray()['counters'])->toMatchArray(['done' => 11, 'not_found' => 1, 'failed' => 0]);
});

it('stops the batch on a certain failure and routes the code', function () {
    Script::$fatal = '15';
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 40])->assertFinal('stopped');

    $batch = batchOf($t);
    expect($batch->status)->toBe(BatchRecord::FAILED)->and($batch->error['code'])->toBe('fatal')
        ->and($batch->summary()['deferred'])->toBeGreaterThan(0)
        ->and($t->engine->batchItems($batch->id, 'pending'))->toBe([])
        ->and($t->engine->batchItems($batch->id, 'queued'))->toBe([]);
    $events = array_filter(array_map(fn ($j) => $j->data['event']['name'] ?? '', $t->view()->journal()), fn ($n) => str_starts_with($n, 'error.invoke.'));
    expect($events)->toHaveCount(1);
});

it('defers what is left when the state is left', function () {
    Script::$down = 100;
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 30])->send('abort')->assertFinal('aborted');

    $batch = batchOf($t);
    expect($batch->status)->toBe(BatchRecord::CANCELLED)->and($batch->summary()['deferred'])->toBe($batch->seen)
        ->and(array_filter($t->view()->effects(), fn (EffectRecord $e) => $e->status === EffectRecord::PENDING))->toBe([]);
});

it('holds a suspended batch and resumes it', function () {
    Script::$down = 1000;
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 30]);
    $t->engine->suspend($t->id(), $t->operator(), 'maintenance');
    $t->drain();
    Script::$down = 0;
    $t->travel('30 minutes', '1 minute')->assertIn('lookup');
    expect(Script::$calls)->toBe([]);

    $t->engine->resume($t->id(), $t->operator(), 'back');
    $t->drain()->travel('5 minutes', '1 minute')->assertFinal('done');
    expect(Script::seen())->toHaveCount(30);
});

it('lets an operator queue failed elements of a running batch again', function () {
    Script::$flaky = ['2' => 2];
    Script::$blocked = '25';
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 25])->assertIn('lookup');
    $batch = batchOf($t);
    expect($t->engine->batchItems($batch->id, 'failed'))->toHaveCount(1);

    $t->engine->retryBatchItems($t->id(), $batch->id, $t->operator(), 'the timeout is fixed');
    Script::$blocked = null;
    $t->drain()->travel('10 minutes', '1 minute')->assertFinal('done');

    expect(array_count_values(Script::seen())['2'])->toBe(3)
        ->and($t->view()->contextArray()['counters'])->toMatchArray(['done' => 22, 'not_found' => 3, 'failed' => 0]);
    $types = array_map(fn ($j) => $j->type, $t->view()->journal());
    expect($types)->toContain('manual.retry_batch');
});

it('shows the progress of a running batch on its node', function () {
    Script::$blocked = '25';
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 25]);
    $snapshot = (new FlowExporter)->snapshot($t->view());
    $node = (array) $snapshot['nodes'];

    expect($node['lookup']['status'])->toBe('active')
        ->and($node['lookup']['badge'])->toBe('20 / 25')
        ->and($node['lookup']['message'])->toBe('done 18, not found 2');
});

it('archives the batches of a finished instance with it', function () {
    $t = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 12])->assertFinal('done');
    $t->travel('31 days', '1 day');
    expect($t->engine->archive())->toBe(1)->and($t->engine->batches($t->id()))->toBe([]);
});

it('lets an operator skip the rest of a batch', function () {
    Script::$down = 50;
    $t2 = (new ProcessTest)->begin(Batches\Enrich::class, ['n' => 20]);
    $batch = batchOf($t2);
    $t2->engine->skipBatch($t2->id(), $batch->id, $t2->operator(), 'not needed today');
    $t2->drain()->assertFinal('done');
    expect($t2->view()->contextArray()['counters'])->toMatchArray(['skipped' => 20, 'total' => 20])
        ->and($t2->view()->contextArray()['counters']['skipped'])->toBe(20);
});

it('recovers a chunk whose worker died after the call', function () {
    $t = (new ProcessTest)->crashAt('batch.after_call')->begin(Batches\Enrich::class, ['n' => 25]);
    $t->recover()->assertFinal('done');

    $done = array_filter($t->view()->journal(), fn ($j) => $j->type === 'batch.done');
    expect($done)->toHaveCount(1)
        ->and(array_unique(Script::seen()))->toHaveCount(25)
        ->and($t->view()->contextArray()['counters'])->toMatchArray(['done' => 22, 'not_found' => 3, 'failed' => 0]);
});

it('rejects a batch handler that is not idempotent', function () {
    $process = new class extends Process
    {
        public const VERSION = 1;

        public function define(Builder $p): void
        {
            $p->state('run', fn (Builder $s) => $s->batch(Batches\Charge::class, items: Batches\Numbers::class));
        }
    };
    expect(fn () => (new ProcessTest)->engine->start($process::class))->toThrow(DefinitionError::class);
});

it('survives a worker dying anywhere on the way of a chunk', function (string $point, int $skip) {
    Script::$flaky = ['4' => 1];
    $t = (new ProcessTest)->crashAt($point, $skip)->begin(Batches\Enrich::class, ['n' => 33]);
    $t->recover()->recover()->assertFinal('done');

    $batch = batchOf($t);
    expect(array_filter($t->view()->journal(), fn ($j) => $j->type === 'batch.done'))->toHaveCount(1)
        ->and(array_values(array_unique(Script::seen())))->toHaveCount(33)
        ->and($batch->summary())->toMatchArray(['done' => 29, 'not_found' => 4, 'failed' => 0, 'deferred' => 0, 'total' => 33])
        ->and($t->engine->batchItems($batch->id, 'pending'))->toBe([])
        ->and($t->engine->batchItems($batch->id, 'queued'))->toBe([])
        ->and($batch->running)->toBe(0);
})->with([
    ['effect.after_claim', 0],
    ['effect.after_claim', 2],
    ['batch.after_call', 0],
    ['batch.after_call', 3],
    ['batch.after_record', 0],
    ['batch.after_record', 4],
]);
