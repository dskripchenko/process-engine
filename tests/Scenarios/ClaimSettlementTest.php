<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Export\FlowExporter;
use Dskripchenko\ProcessEngine\Runtime\SimpleContainer;
use Dskripchenko\ProcessEngine\Runtime\SyncDispatcher;
use Dskripchenko\ProcessEngine\Runtime\VirtualClock;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InMemoryStore;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Scenarios\ClaimSettlement as C;
use Dskripchenko\ProcessEngine\Tests\Support\SchemaValidator;
use Dskripchenko\ProcessEngine\Versioning\Migration;

require_once __DIR__.'/ClaimSettlement/Process.php';

function insurerContainer(C\InsurerSystem $insurer): SimpleContainer
{
    $container = new SimpleContainer;
    foreach ([C\SubmitToInsurer::class, C\SendDocuments::class, C\ReportMissingDocuments::class, C\SendCompensationOffer::class, C\BookService::class, C\PayOut::class] as $class) {
        $container->set($class, new $class($insurer));
    }

    return $container;
}

function claim(?C\InsurerSystem $insurer = null): ProcessTest
{
    $insurer ??= new C\InsurerSystem;
    $t = new ProcessTest(container: insurerContainer($insurer));

    return $t->begin(C\ClaimSettlement::class, new C\ClaimContext(policyId: 'P-1'));
}

/**
 * From a fresh claim to the insurer's queue.
 */
function submitted(ProcessTest $t): ProcessTest
{
    return $t->send(new C\FormSubmitted(['damage' => 'glass']))
        ->send('client.signed')
        ->assertIn('awaitingParty');
}

function agree(ProcessTest $t, string $compensation): ProcessTest
{
    return $t->publish(new C\PartyDecision(true), 'APP-1', key: 'decision')
        ->assertIn('offering', 'choosingSlot')
        ->send('client.accepted', ['type' => $compensation])
        ->send('client.slot-chosen', ['slotId' => 'mon-10']);
}

it('settles a repair claim on the happy path', function () {
    $t = submitted(claim())->assertContext('externalId', 'APP-1')->assertContext('applicationDocId', 'doc-P-1');
    agree($t, 'repair')->assertFinal('closed')->assertContext('archiveId', 'archive-APP-1')->assertContext('payoutId', null);

    expect($t->view()->label())->toBe('Settled');
});

it('goes through three document requests, one of them timing out', function () {
    $insurer = new C\InsurerSystem;
    $t = submitted(claim($insurer));
    foreach ([1, 2] as $n) {
        $t->publish(new C\DocumentsRequested(['photo']), 'APP-1', key: "req-{$n}")
            ->assertIn('awaitingDocuments')
            ->send('client.documents-uploaded')
            ->assertIn('awaitingParty');
    }
    $t->publish(new C\DocumentsRequested(['receipt']), 'APP-1', key: 'req-3')->travel('10 days')->assertIn('awaitingParty');

    $t->assertContext('documentRequests', 3);
    expect($insurer->messages)->toBe(['documents for APP-1', 'documents for APP-1', 'no documents for APP-1']);
    $snapshot = json_decode(json_encode((new FlowExporter)->snapshot($t->view(), ['review'])), true);
    expect($snapshot['nodes']['awaitingDocuments']['count'])->toBe(3);
});

it('abandons an application left unsigned for 14 days', function () {
    claim()->send(new C\FormSubmitted(['damage' => 'dent']))->travel('15 days', '1 day')->assertFinal('abandoned');
});

it('refuses an invalid form at the sender', function () {
    $t = claim();
    expect(fn () => $t->engine->send($t->id(), new C\FormSubmitted([])))->toThrow(InvalidArgumentException::class, 'Describe the damage');
});

it('ends on a rejection by the insurer', function () {
    submitted(claim())->publish(new C\PartyDecision(false, 'not covered'), 'APP-1')->assertFinal('rejected')->assertContext('rejectionReason', 'not covered');
});

it('buffers a decision that comes before the instance waits for it, and drops its duplicate', function () {
    $t = claim()->send(new C\FormSubmitted(['damage' => 'glass']));
    expect($t->engine->publish(new C\PartyDecision(true), 'APP-1', key: 'msg-1')->status)->toBe('buffered');
    expect($t->engine->publish(new C\PartyDecision(true), 'APP-1', key: 'msg-1')->status)->toBe('duplicate');

    $t->send('client.signed')->assertIn('offering', 'choosingSlot');
    $steps = array_filter($t->view()->journal(), fn ($j) => $j->type === 'step' && ($j->data['event']['name'] ?? '') === 'party.decision');
    expect($steps)->toHaveCount(1);
});

it('never submits twice when the worker dies between the call and the record', function () {
    $insurer = new C\InsurerSystem;
    $t = new ProcessTest(container: insurerContainer($insurer));
    $t->begin(C\ClaimSettlement::class, new C\ClaimContext(policyId: 'P-1'))
        ->send(new C\FormSubmitted(['damage' => 'glass']))
        ->crashAt('effect.after_call')
        ->send('client.signed');
    expect($t->crashes)->toHaveCount(1);

    $t->recover()->assertIn('awaitingParty')->assertContext('externalId', 'APP-1');
    expect($insurer->applications)->toHaveCount(1);
    expect(array_map(fn ($j) => $j->type, $t->view()->journal()))->toContain('effect.unknown', 'effect.reconciled');
});

it('stops at an unknown payout, raises an incident and continues after an operator decision', function () {
    $t = submitted(claim());
    $t->fake(C\PayOut::class, throws: new RuntimeException('bank timeout'), reconcile: Outcome::stillUnknown());
    agree($t, 'money')->assertIn('payout')->travel('2 days', '1 hour');

    $t->assertEffect(C\PayOut::class, EffectRecord::UNKNOWN)->assertIncident(IncidentRecord::EFFECT_UNKNOWN)->assertCalls(C\PayOut::class, 1);
    $nodes = json_decode(json_encode((new FlowExporter)->snapshot($t->view())), true)['nodes'];
    expect($nodes['payout']['status'])->toBe('error');

    $t->resolveEffect(C\PayOut::class, true, 'TR-confirmed')->assertFinal('closed')->assertContext('payoutId', 'TR-confirmed');
});

it('undoes the service booking when the client cancels', function () {
    $insurer = new C\InsurerSystem;
    $t = submitted(claim($insurer))
        ->publish(new C\PartyDecision(true), 'APP-1')
        ->send('client.slot-chosen', ['slotId' => 'tue-9'])
        ->assertIn('booked', 'offering')
        ->send('client.cancelled')
        ->assertFinal('cancelled');

    expect($insurer->cancelledBookings)->toBe(['booking-tue-9']);
});

it('migrates waiting instances to version 2 and leaves the others on version 1', function () {
    $insurer = new C\InsurerSystem;
    $t = new ProcessTest(container: insurerContainer($insurer));
    $waiting = $t->begin(C\ClaimSettlement::class, new C\ClaimContext(policyId: 'P-1'));
    submitted($waiting);
    $filling = $t->engine->start(C\ClaimSettlement::class, new C\ClaimContext(policyId: 'P-2'))->id();
    $t->engine->start(C\ClaimSettlementV2::class, new C\ClaimContext(policyId: 'P-3'));

    $migration = Migration::from('claim-settlement', 1)->to(2)->mapState('awaitingParty', 'awaitingParty');
    $report = $t->engine->migrate($migration, $t->operator(), 'add the fraud check', dryRun: false);
    $t->drain();

    expect($report->eligible)->toBe([$waiting->id()])->and($report->skipped[$filling])->toContain('filling');
    $t->publish(new C\PartyDecision(true), 'APP-1')->assertIn('fraudCheck');
    expect($t->engine->get($filling)->version())->toBe(1);
});

it('books once when two workers race on the same event', function () {
    $store = new InMemoryStore;
    $clock = new VirtualClock;
    $insurer = new C\InsurerSystem;
    $engines = array_map(fn ($w) => new Engine($store, insurerContainer($insurer), new SyncDispatcher, $clock, worker: $w), ['A', 'B']);
    $id = $engines[0]->start(C\ClaimSettlement::class, new C\ClaimContext(policyId: 'P-1'))->id();
    $engines[0]->drain();
    foreach (['form' => [new C\FormSubmitted(['damage' => 'x'])], 'signed' => ['client.signed']] as $key => [$event]) {
        $engines[0]->send($id, $event, key: $key);
        $engines[0]->drain();
    }
    $engines[1]->publish(new C\PartyDecision(true), 'APP-1', key: 'd');
    $engines[1]->drain();

    $engines[0]->send($id, 'client.slot-chosen', ['slotId' => 's1'], key: 'slot');
    $engines[1]->send($id, 'client.slot-chosen', ['slotId' => 's1'], key: 'slot');
    for ($i = 0; $i < 10; $i++) {
        $engines[$i % 2]->drain(2);
    }

    $bookings = array_filter($engines[0]->get($id)->effects(), fn (EffectRecord $e) => $e->class === C\BookService::class);
    expect($bookings)->toHaveCount(1)->and($engines[0]->get($id)->is('booked'))->toBeTrue();
});

it('exports the reference process for flow', function () {
    $definition = definition(C\ClaimSettlement::class);
    $validator = new SchemaValidator(__DIR__.'/../Fixtures/flow/graph.schema.json');
    $graph = (new FlowExporter)->graph($definition);

    expect($validator->validate($graph))->toBe([])
        ->and(array_column($graph['nodes'], 'type', 'id'))->toMatchArray([
            'application' => 'subprocess', 'review' => 'subprocess', 'agreement' => 'subprocess',
            'route' => 'decision', 'payout' => 'task', 'closed' => 'end',
        ]);
});
