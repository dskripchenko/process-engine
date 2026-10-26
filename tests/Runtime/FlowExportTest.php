<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Export\FlowExporter;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;
use Dskripchenko\ProcessEngine\Tests\Support\SchemaValidator;

function graphSchema(): SchemaValidator
{
    return new SchemaValidator(__DIR__.'/../Fixtures/flow/graph.schema.json');
}

function snapshotSchema(): SchemaValidator
{
    return new SchemaValidator(__DIR__.'/../Fixtures/flow/snapshot.schema.json');
}

it('exports a definition as a flow graph that matches the flow schema', function (string $class) {
    $graph = (new FlowExporter)->graph(definition($class));
    expect(graphSchema()->validate($graph))->toBe([]);
})->with([
    Runtime\Order::class,
    Fixtures\Nested::class,
    Fixtures\Parallel::class,
    Fixtures\Application::class,
    Fixtures\WithHistory::class,
    Fixtures\Choosing::class,
    Fixtures\Invoking::class,
]);

it('maps states to flow node types', function () {
    $graph = (new FlowExporter)->graph(definition(Fixtures\Application::class));
    $types = array_column($graph['nodes'], 'type', 'id');

    expect($types)->toMatchArray([
        '#root.__start' => 'start',
        'signing' => 'subprocess',
        'registered' => 'subprocess',
        'closed' => 'end',
        'spawn:#root.spawn.signing' => 'subprocess',
    ]);
    $signing = array_values(array_filter($graph['nodes'], fn ($n) => $n['id'] === 'signing'))[0];
    expect($signing['subgraph'])->toBe('signing@1');

    $registered = array_values(array_filter($graph['nodes'], fn ($n) => $n['id'] === 'registered'))[0];
    expect(array_column($registered['subgraph']['nodes'], 'type', 'id'))->toMatchArray([
        'registered_renderDocument' => 'task',
        'registered__done' => 'end',
    ]);
});

it('labels edges with events, timers and routing conditions', function () {
    $graph = (new FlowExporter)->graph(definition(Runtime\Order::class));
    $edges = array_map(fn ($e) => [$e['from'], $e['to'], $e['label'] ?? null, $e['condition'] ?? null], $graph['edges']);

    expect($edges)->toContain(
        ['#root.__start', 'new', null, null],
        ['new', 'rendering', 'submit', null],
        ['waiting', 'approved', 'party.decision', 'approved = true'],
        ['waiting', 'expired', '⏱ 3 days', null],
        ['rendering', 'paying', 'done', null],
    );
});

it('groups parallel regions and drills into levels', function () {
    $exporter = new FlowExporter;
    $root = $exporter->graph(definition(Fixtures\Parallel::class));
    $agreement = array_values(array_filter($root['nodes'], fn ($n) => $n['id'] === 'agreement'))[0];
    expect($agreement['data'])->toBe(['kind' => 'parallel', 'join' => 'all'])
        ->and(array_column($agreement['subgraph']['groups'], 'id'))->toBe(['left', 'right'])
        ->and(array_column($agreement['subgraph']['nodes'], 'group', 'id'))->toMatchArray(['l1' => 'left', 'rf' => 'right']);

    $level = $exporter->graph(definition(Fixtures\Parallel::class), ['agreement']);
    expect($level)->toEqual($agreement['subgraph']);
});

it('exports the state of an instance for monitoring', function () {
    $t = (new ProcessTest)->begin(Runtime\Order::class)->send('submit', ['externalId' => 'E', 'files' => ['a']]);
    $snapshot = (new FlowExporter)->snapshot($t->view());

    expect(snapshotSchema()->validate($snapshot))->toBe([]);
    $nodes = json_decode(json_encode($snapshot), true)['nodes'];
    expect($nodes['waiting']['status'])->toBe('waiting')
        ->and($nodes['waiting']['message'])->toContain('party.decision')
        ->and($nodes['new']['status'])->toBe('done')
        ->and($nodes['paying']['status'])->toBe('done')
        ->and($snapshot['current'])->toBe(['waiting'])
        ->and($snapshot['path'])->toBe(['new', 'rendering', 'paying', 'booking', 'uploading', 'waiting']);

    $edges = json_decode(json_encode($snapshot), true)['edges'];
    expect($edges)->not->toBeEmpty();
});

it('shows unknown outcomes and incidents on the node', function () {
    $t = (new ProcessTest)
        ->fake(Runtime\Charge::class, throws: new RuntimeException('timeout'), reconcile: Outcome::stillUnknown())
        ->begin(Runtime\Order::class)
        ->send('submit', ['externalId' => 'E']);
    $nodes = json_decode(json_encode((new FlowExporter)->snapshot($t->view())), true)['nodes'];
    expect($nodes['paying']['status'])->toBe('unknown');

    $t->travel('3 days', '6 hours');
    $nodes = json_decode(json_encode((new FlowExporter)->snapshot($t->view())), true)['nodes'];
    expect($nodes['paying']['status'])->toBe('error')->and($nodes['paying']['badge'])->toBe(1);
});

it('counts loops and aggregates instances per state', function () {
    $t = (new ProcessTest)->begin(Fixtures\Nested::class)->send('next');
    $snapshot = json_decode(json_encode((new FlowExporter)->snapshot($t->view(), ['work'])), true);
    expect($snapshot['current'])->toBe(['two'])->and($snapshot['nodes']['one']['status'])->toBe('done');

    $overview = json_decode(json_encode((new FlowExporter)->overview(definition(Fixtures\Nested::class), ['work' => 3, 'two' => 3])), true);
    expect($overview['nodes'])->toBe(['work' => ['count' => 3, 'status' => 'active']]);
});

it('keeps ids unique across nodes, edges and groups at every level', function (string $class) {
    $check = function (array $graph) use (&$check) {
        $ids = [...array_column($graph['nodes'], 'id'), ...array_column($graph['edges'], 'id'), ...array_column($graph['groups'] ?? [], 'id')];
        expect(count($ids))->toBe(count(array_unique($ids)));
        $nodeIds = array_column($graph['nodes'], 'id');
        foreach ($graph['edges'] as $edge) {
            expect($nodeIds)->toContain($edge['from'], $edge['to']);
        }
        foreach ($graph['nodes'] as $node) {
            if (is_array($node['subgraph'] ?? null)) {
                $check($node['subgraph']);
            }
        }
    };
    $check((new FlowExporter)->graph(definition($class)));
})->with([Runtime\Order::class, Fixtures\Parallel::class, Fixtures\Application::class, Fixtures\WithHistory::class]);

it('catches schema violations (the validator is not a no-op)', function () {
    expect(graphSchema()->validate(['nodes' => [['id' => '', 'type' => 'state']], 'edges' => [['id' => 'x', 'from' => 1]]]))
        ->not->toBe([]);
});
