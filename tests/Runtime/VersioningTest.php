<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Runtime\DefinitionVersionConflict;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;
use Dskripchenko\ProcessEngine\Versioning\Migration;

it('keeps old instances on their version while new ones start on the latest', function () {
    $t = new ProcessTest;
    $old = $t->engine->start(Runtime\VersionedV1::class)->id();
    $new = $t->engine->start(Runtime\VersionedV2::class)->id();
    $byName = $t->engine->start('versioned')->id();

    expect($t->engine->get($old)->version())->toBe(1)
        ->and($t->engine->get($new)->version())->toBe(2)
        ->and($t->engine->get($byName)->version())->toBe(2);

    $t->engine->send($old, 'go');
    $t->engine->send($new, 'go');
    $t->drain();
    expect($t->engine->get($old)->final())->toBe('done')
        ->and($t->engine->get($new)->active())->toBe(['checking']);
});

it('refuses a changed graph under the same version', function () {
    $t = new ProcessTest;
    $t->engine->start(Runtime\VersionedV1::class);
    $t->engine->start(Runtime\VersionedV1Changed::class);
})->throws(DefinitionVersionConflict::class, 'bump VERSION');

it('executes a stored version whose class has moved on', function () {
    $t = new ProcessTest;
    $stored = Definition::fromArray([
        'format' => 1, 'name' => 'legacy', 'version' => 1,
        'root' => ['id' => '#root', 'type' => 'compound', 'states' => [
            ['id' => 'a', 'on' => [['event' => 'go', 'target' => 'b']]],
            ['id' => 'b', 'type' => 'final'],
        ]],
    ]);
    $t->engine->kernel->definitions->register($stored);
    $id = $t->engine->start('legacy')->id();
    $t->engine->send($id, 'go');
    $t->drain();

    expect($t->engine->get($id)->final())->toBe('b');
});

it('reports eligible and skipped instances in a dry run, then migrates', function () {
    $t = new ProcessTest;
    $waiting = $t->engine->start(Runtime\VersionedV1::class)->id();
    $finished = $t->engine->start(Runtime\VersionedV1::class)->id();
    $t->engine->send($finished, 'go');
    $t->drain();
    $t->engine->start(Runtime\VersionedV2::class);

    $migration = Migration::from(Runtime\VersionedV1::class, 1)->to(2)
        ->mapState('waiting', 'awaiting')
        ->context(fn (array $c) => [...$c, 'migrated' => true]);

    $dry = $t->engine->migrate($migration, $t->operator(), 'move to v2');
    expect($dry->dryRun)->toBeTrue()->and($dry->eligible)->toBe([$waiting])->and($dry->skipped)->toBe([]);
    expect($t->engine->get($waiting)->version())->toBe(1);

    $t->engine->migrate($migration, $t->operator(), 'move to v2', dryRun: false);
    $t->drain();
    $view = $t->engine->get($waiting);
    expect($view->version())->toBe(2)
        ->and($view->active())->toBe(['awaiting'])
        ->and($view->contextArray())->toBe(['migrated' => true]);

    $timers = $view->timers();
    expect($timers)->toHaveCount(1)->and($timers[0]->fireAt->format('Y-m-d'))->toBe($t->clock->now()->modify('+5 days')->format('Y-m-d'));
    expect(array_map(fn ($j) => $j->type, $view->journal()))->toContain('migrated');

    $t->travel('3 days');
    expect($t->engine->get($waiting)->status())->toBe('running');
    $t->engine->send($waiting, 'go');
    $t->drain();
    expect($t->engine->get($waiting)->active())->toBe(['checking']);
});

it('skips instances with dropped states, incidents or failed conditions', function () {
    $t = new ProcessTest;
    $t->engine->start(Runtime\VersionedV2::class);
    $a = $t->engine->start(Runtime\VersionedV1::class)->id();

    $dropped = $t->engine->migrate(Migration::from('versioned', 1)->to(2)->dropState('waiting'), $t->operator(), 'x');
    expect($dropped->skipped[$a])->toContain('dropped state');

    $unmapped = $t->engine->migrate(Migration::from('versioned', 1)->to(2), $t->operator(), 'x');
    expect($unmapped->skipped[$a])->toContain('no counterpart');

    $conditional = $t->engine->migrate(Migration::from('versioned', 1)->to(2)->mapState('waiting', 'awaiting')->when(fn () => false), $t->operator(), 'x');
    expect($conditional->skipped[$a])->toContain('condition');
});

it('finds live versions whose code is gone', function () {
    $t = new ProcessTest;
    $t->engine->kernel->definitions->register(Definition::fromArray([
        'format' => 1, 'name' => 'orphaned', 'version' => 1, 'class' => Runtime\Order::class,
        'root' => ['id' => '#root', 'type' => 'compound', 'states' => [
            ['id' => 'a', 'entry' => [['type' => 'effect', 'class' => 'App\\Gone\\Effect', 'id' => 'x']],
                'on' => [['event' => 'go', 'guard' => ['type' => 'method', 'name' => 'removedGuard'], 'target' => 'b']]],
            ['id' => 'b', 'type' => 'final'],
        ]],
    ]));
    $t->engine->start('orphaned');
    $problems = array_column($t->engine->doctor(), 'problem');

    expect(implode("\n", $problems))->toContain('App\\Gone\\Effect')->toContain('removedGuard');
});

it('defines a process from data, as a visual editor would, and runs it by name', function () {
    $t = new ProcessTest;
    $data = [
        'format' => 1, 'name' => 'edited', 'version' => 1,
        'root' => ['id' => '#root', 'type' => 'compound', 'states' => [
            ['id' => 'open', 'on' => [['event' => 'close', 'target' => 'closed', 'guard' => ['type' => 'predicate', 'expr' => ['eq' => ['$.event.ok', true]]]]]],
            ['id' => 'closed', 'type' => 'final'],
        ]],
    ];

    $record = $t->engine->define($data);
    expect($t->engine->define($data)->id)->toBe($record->id);   // the same graph again: a no-op

    $id = $t->engine->start('edited')->id();
    $t->engine->send($id, 'close', ['ok' => false]);
    $t->drain();
    expect($t->engine->get($id)->active())->toBe(['open']);

    $t->engine->send($id, 'close', ['ok' => true]);
    $t->drain();
    expect($t->engine->get($id)->final())->toBe('closed');
});

it('refuses an invalid definition with every problem named', function () {
    (new ProcessTest)->engine->define([
        'format' => 1, 'name' => 'broken', 'version' => 1,
        'root' => ['id' => '#root', 'type' => 'compound', 'states' => [
            ['id' => 'a', 'on' => [['event' => 'go', 'target' => 'nowhere']]],
        ]],
    ]);
})->throws(DefinitionError::class, 'Invalid definition');
