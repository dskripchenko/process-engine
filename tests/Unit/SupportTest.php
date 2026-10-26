<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Support\Duration;
use Dskripchenko\ProcessEngine\Support\Hydrator;
use Dskripchenko\ProcessEngine\Support\JsonPatch;
use Dskripchenko\ProcessEngine\Support\Uuid;
use Dskripchenko\ProcessEngine\Tests\Fixtures\OrderContext;

it('generates sortable v7 uuids', function () {
    $a = Uuid::v7(new DateTimeImmutable('2026-01-01T00:00:00Z'));
    $b = Uuid::v7(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    expect(Uuid::isValid($a))->toBeTrue()
        ->and($a[14])->toBe('7')
        ->and(strcmp($a, $b))->toBeLessThan(0);
});

it('parses human and ISO durations', function (string $spec, string $expected) {
    $from = new DateTimeImmutable('2026-01-30T10:00:00+00:00');
    expect(Duration::add($from, $spec)->format('Y-m-d H:i'))->toBe($expected);
})->with([
    ['14 days', '2026-02-13 10:00'],
    ['PT90M', '2026-01-30 11:30'],
    ['10s', '2026-01-30 10:00'],
    ['2h', '2026-01-30 12:00'],
    ['1 day, 2 hours', '2026-01-31 12:00'],
]);

it('rejects nonsense durations', function () {
    expect(Duration::isValid('soon'))->toBeFalse()->and(Duration::isValid('5 parsecs'))->toBeFalse();
});

it('diffs and applies JSON patches', function () {
    $before = ['a' => 1, 'b' => ['c' => 2, 'd' => 3], 'list' => [1, 2], 'gone' => true];
    $after = ['a' => 1, 'b' => ['c' => 5, 'd' => 3], 'list' => [1, 2, 3], 'new' => 'x'];
    $patch = JsonPatch::diff($before, $after);

    expect(JsonPatch::apply($before, $patch))->toBe($after)
        ->and(array_column($patch, 'op'))->toBe(['replace', 'replace', 'remove', 'add']);
});

it('round-trips objects through arrays', function () {
    $context = new OrderContext(orderId: 'o-1', requests: 2, files: ['a']);
    $data = Hydrator::dehydrate($context);
    expect($data)->toBe(['orderId' => 'o-1', 'requests' => 2, 'document' => null, 'reason' => null, 'files' => ['a']]);

    $back = Hydrator::hydrate(OrderContext::class, $data);
    expect($back)->toEqual($context);
});
