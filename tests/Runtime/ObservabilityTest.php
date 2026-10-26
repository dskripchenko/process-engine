<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceQuery;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;

it('shows the client status and the actions available now', function () {
    $t = (new ProcessTest)->begin(Runtime\Agreement::class);
    $view = $t->view();

    expect($view->label())->toBe('Sign the agreement');
    $events = array_map(fn ($e) => [$e->event, $e->label, $e->kind], $view->expects());
    expect($events)->toContain(['client.agreed', 'Sign', 'event'], ['client.declined', 'Decline', 'event'])
        ->and(array_column(array_map(fn ($e) => $e->toArray(), $view->expects()), 'kind'))->toContain('timer');

    $t->send('client.agreed');
    expect($t->view()->label())->toBe('Signed');
});

it('explains why the instance is where it is', function () {
    $t = (new ProcessTest)->begin(Runtime\Agreement::class)->send('client.declined');
    $why = $t->view()->why();

    expect($why->type)->toBe('step')
        ->and($why->data['event']['name'])->toBe('client.declined')
        ->and($why->data['transitions'][0])->toMatchArray(['source' => 'offer', 'target' => 'declined']);
});

it('raises an incident when a step throws and redelivers the event after a fix', function () {
    $t = (new ProcessTest)->begin(Runtime\Agreement::class)->send('boom');
    $t->assertIncident(IncidentRecord::STEP_ERROR)->assertIn('offer');
    $failed = $t->view()->inbox(InboxRecord::FAILED);
    expect($failed)->toHaveCount(1);

    $t->engine->patchContext($t->id(), [['op' => 'add', 'path' => '/fixed', 'value' => true]], $t->operator(), 'deployed the fix');
    $t->engine->redeliver($t->id(), (int) $failed[0]->id, $t->operator(), 'retry after fix');
    $t->drain()->assertFinal('signed');

    $incident = $t->view()->incidents()[0];
    $t->engine->resolveIncident($incident->id, $t->operator(), 'fixed', 'redelivered');
    $t->drain();
    expect($t->view()->incidents())->toBe([])->and($t->view()->record()->hasIncidents)->toBeFalse();
});

it('lists instances by state and reports health', function () {
    $t = new ProcessTest;
    $a = $t->engine->start(Runtime\Agreement::class)->id();
    $t->engine->start(Runtime\Agreement::class);
    $t->engine->send($a, 'client.agreed');
    $t->drain();

    expect($t->engine->instances(new InstanceQuery(state: 'offer')))->toHaveCount(1)
        ->and($t->engine->stateCounts(Runtime\Agreement::class))->toBe(['offer' => 1]);

    $health = $t->engine->health();
    expect($health['running'])->toBe(1)->and($health['overdue_timers'])->toBe(0);

    $t->clock->advance('3 days');
    expect($t->engine->health()['overdue_timers'])->toBe(1);
    expect($t->engine->get($t->engine->instances(new InstanceQuery(state: 'offer'))[0]->id())->overdueTimers())->toHaveCount(1);
});

it('emits signals after commit', function () {
    $t = (new ProcessTest)->begin(Runtime\Agreement::class)->send('client.agreed');
    $types = array_map(fn ($s) => $s->type, $t->signals->signals);

    expect($types)->toContain('instance.started', 'step.committed', 'instance.completed');
});
