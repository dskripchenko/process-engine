<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Support\JsonPatch;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;

/**
 * The invariants every crash must preserve (ADR-013).
 */
function assertInvariants(ProcessTest $t): void
{
    $view = $t->view();

    // I1: every inbox entry ends processed (or deliberately ignored/discarded), none stuck.
    expect($view->inbox(InboxRecord::PENDING))->toBe([]);

    // I2/I3: at most one call per at-most-once key; effect keys are unique.
    $charges = array_filter($t->fakeOf(Runtime\Charge::class)->calls, fn ($fx) => true);
    $keys = array_map(fn ($fx) => $fx->key, $charges);
    expect(count($keys))->toBe(count(array_unique($keys)))->and(count($keys))->toBeLessThanOrEqual(1);
    $effectKeys = array_map(fn (EffectRecord $e) => $e->key, $view->effects());
    expect(count($effectKeys))->toBe(count(array_unique($effectKeys)));

    // One step per external event.
    $submits = array_filter($view->journal(), fn ($j) => $j->type === 'step' && ($j->data['event']['name'] ?? null) === 'submit');
    expect($submits)->toHaveCount(1);

    // I4: folding the journal's context patches gives the stored context.
    $context = [];
    foreach ($view->journal() as $entry) {
        if (isset($entry->data['context_patch'])) {
            $context = JsonPatch::apply($context, $entry->data['context_patch']);
        }
    }
    expect($context)->toEqual($view->contextArray());
}

function crashScenario(string $point, int $skip, bool $expire): ProcessTest
{
    $t = (new ProcessTest)
        ->fake(Runtime\Charge::class, returns: 'tx-1', reconcile: Outcome::succeeded('tx-1'))
        ->crashAt($point, $skip)
        ->begin(Runtime\Order::class, ['n' => 1]);
    $t->send('submit', ['externalId' => 'EXT', 'files' => ['a', 'b']], key: 'submit-1');
    // Time passes without disarming: a crash armed further down still happens.
    $t->travel('10 minutes', '1 minute');
    // A redelivery of the same event after the crash must change nothing.
    $t->send('submit', ['externalId' => 'EXT', 'files' => ['a', 'b']], key: 'submit-1');
    if ($expire) {
        $t->travel('3 days', '6 hours')->travel('10 minutes', '1 minute');
    } else {
        $t->publish('party.decision', 'EXT', ['approved' => true], key: 'decision-1')->travel('10 minutes', '1 minute');
    }

    return $t->recover();
}
