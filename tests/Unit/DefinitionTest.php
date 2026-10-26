<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Builder\Compiler;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Definition\Validator;
use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Interpreter\Interpreter;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Tests\Fixtures;

it('produces a stable canonical form and hash', function () {
    $def = definition(Fixtures\DocumentApproval::class);
    expect($def->name())->toBe('document-approval')
        ->and($def->version())->toBe(1)
        ->and($def->hash())->toHaveLength(64);

    $again = Definition::fromJson($def->toJson());
    expect($again->hash())->toBe($def->hash());
});

it('names processes by attribute or by kebab-case class name', function () {
    expect(Fixtures\DocumentApproval::processName())->toBe('document-approval')
        ->and(Fixtures\Linear::processName())->toBe('linear');
});

it('expands step chains, final routing and match tables into plain transitions', function () {
    $data = definition(Fixtures\Application::class)->toArray();
    $states = array_column($data['root']['states'], null, 'id');

    expect(array_column($states['registered']['states'], 'id'))
        ->toBe(['registered_renderDocument', 'registered_uploadFile', 'registered__done']);

    $finals = array_filter($states['signing']['on'], fn ($t) => $t['event'] === 'done.invoke.signing.signing');
    expect(array_values(array_map(fn ($t) => [$t['guard']['expr']['eq'][1], $t['target']], $finals)))
        ->toBe([['signed', 'registered'], ['refused', 'refused'], ['expired', 'refused']]);
});

it('stores enum cases as state ids and checks the enum', function () {
    $def = definition(Fixtures\Typed::class);
    expect($def->has('draft'))->toBeTrue()->and($def->toArray()['states'])->toBe(Fixtures\OrderState::class);
});

it('rejects anonymous closures', function () {
    $class = new class extends Process
    {
        public const VERSION = 1;

        public function define(Builder $p): void
        {
            $p->state('a', fn (Builder $s) => $s->on('go', to: 'b', when: fn () => true));
            $p->final('b');
        }
    };
    Compiler::compile($class::class);
})->throws(DefinitionError::class, 'method of the process');

it('rejects effects without retry semantics', function () {
    $class = new class extends Process
    {
        public const VERSION = 1;

        public function define(Builder $p): void
        {
            $p->state('a', fn (Builder $s) => $s->entry(Fixtures\NoSemantics::class));
        }
    };
    Compiler::compile($class::class);
})->throws(DefinitionError::class, 'no retry semantics');

it('rejects unknown targets and unrouted child finals', function () {
    $unknown = new class extends Process
    {
        public const VERSION = 1;

        public function define(Builder $p): void
        {
            $p->state('a', fn (Builder $s) => $s->on('go', to: 'nowhere'));
        }
    };
    expect(fn () => Compiler::compile($unknown::class))->toThrow(DefinitionError::class, 'unknown state `nowhere`');

    $unrouted = new class extends Process
    {
        public const VERSION = 1;

        public function define(Builder $p): void
        {
            $p->state('a', fn (Builder $s) => $s->invoke(Fixtures\Signing::class, onDone: ['signed' => 'b']));
            $p->final('b');
        }
    };
    expect(fn () => Compiler::compile($unrouted::class))->toThrow(DefinitionError::class, 'not routed: refused, expired');
});

it('warns about unreachable states and stuck choices', function () {
    $issues = Validator::validate([
        'format' => 1, 'name' => 'x', 'version' => 1,
        'root' => ['id' => '#root', 'type' => 'compound', 'states' => [
            ['id' => 'a', 'type' => 'choice', 'always' => [['guard' => ['type' => 'predicate', 'expr' => ['eq' => ['$.context.a', 1]]], 'target' => 'b']]],
            ['id' => 'b', 'type' => 'final'],
            ['id' => 'lonely', 'type' => 'atomic'],
        ]],
    ]);
    $codes = array_map(fn ($i) => $i->code, $issues);

    expect($codes)->toContain('choice-stuck', 'unreachable');
});

it('validates durations and poll semantics', function () {
    $issues = Validator::validate([
        'format' => 1, 'name' => 'x', 'version' => 1,
        'root' => ['id' => '#root', 'type' => 'compound', 'states' => [
            ['id' => 'a', 'timers' => [['id' => 't', 'delay' => ['duration' => 'soon']]],
                'polls' => [['id' => 'p', 'effect' => Fixtures\ChargeCard::class, 'every' => ['duration' => '1 minute']]]],
        ]],
    ]);
    $codes = array_map(fn ($i) => $i->code, $issues);

    expect($codes)->toContain('duration', 'poll-semantics');
});

it('loads a definition from JSON and executes it without the process class', function () {
    $json = json_encode([
        'format' => 1, 'name' => 'json-flow', 'version' => 1,
        'root' => ['id' => '#root', 'type' => 'compound', 'states' => [
            ['id' => 'a', 'on' => [['event' => 'go', 'guard' => ['type' => 'predicate', 'expr' => ['gt' => ['$.event.amount', 0]]],
                'actions' => [['type' => 'assign', 'set' => ['$.context.amount' => ['path' => '$.event.amount']]]], 'target' => 'b']]],
            ['id' => 'b', 'type' => 'final', 'output' => ['path' => '$.context.amount']],
        ]],
    ]);
    $def = Definition::fromJson((string) $json);
    $interpreter = new Interpreter;
    $s = $interpreter->start($def, [], null, ctx())->snapshot;
    $r = $interpreter->step($def, $s, new Event('go', ['amount' => 5]), ctx());

    expect($r->snapshot->output)->toBe(5);
});
