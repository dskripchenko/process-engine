<?php

declare(strict_types=1);
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime\Charge;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime\Order;

it('actually crashes at every fault point', function (string $point) {
    $t = crashScenario($point, 0, expire: $point === 'ticker.after_fire');
    expect($t->crashes)->not->toBeEmpty("no crash happened at {$point}");
})->with([
    'runner.before_commit', 'runner.after_commit', 'effect.after_claim',
    'effect.after_call', 'effect.after_record', 'ticker.after_fire',
]);

it('crashes during reconciliation when an outcome is unknown', function () {
    $t = (new ProcessTest)
        ->fake(Charge::class, throws: new RuntimeException('timeout'), reconcile: Outcome::succeeded('tx-r'))
        ->crashAt('reconcile.after_call')
        ->begin(Order::class)
        ->send('submit', ['externalId' => 'E', 'files' => []]);
    expect($t->crashes)->toHaveCount(1);

    $t->recover()->assertContext('payment', 'tx-r')->assertCalls(Charge::class, 1);
    expect(count($t->fakeOf(Charge::class)->reconciles))->toBe(2);
});
