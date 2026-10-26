<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;

it('refuses invalid events at the sender', function () {
    $t = (new ProcessTest)->begin(Runtime\Order::class);
    $t->engine->send($t->id(), new Runtime\FormSubmitted(['damage' => 'glass']));
    expect(fn () => $t->engine->send($t->id(), new Runtime\FormSubmitted([])))->toThrow(InvalidArgumentException::class, 'damage is required');

    $t->engine->addValidator(function ($event) {
        if ($event->name === 'submit' && ! isset($event->payload['externalId'])) {
            throw new InvalidArgumentException('externalId is required');
        }
    });
    expect(fn () => $t->engine->send($t->id(), 'submit'))->toThrow(InvalidArgumentException::class, 'externalId');
});

it('ends the losing regions of a join-any race, their effects and children included', function () {
    $t = (new ProcessTest)
        ->fake(Runtime\Render::class, using: fn () => throw new RuntimeException('slow'))
        ->begin(Runtime\Race::class);
    $child = $t->view()->children()[0];

    $t->send('a.done')->assertFinal('won');
    expect($t->engine->get($child->id)->status())->toBe('cancelled');
    $render = $t->effect(Runtime\Render::class);
    expect($render->status)->toBeIn([EffectRecord::CANCELLED, EffectRecord::PENDING]);
    $t->travel('1 hour', '5 minutes');
    expect($t->fakeOf(Runtime\Render::class)->calls)->toHaveCount(1);
});

it('runs one child per element and collects their outputs in order', function () {
    $t = (new ProcessTest)->begin(Runtime\Batch::class, ['documents' => ['d1', 'd2', 'd3']]);
    $children = $t->view()->children();
    expect($children)->toHaveCount(3);

    foreach ([2, 0, 1] as $i) {
        $t->engine->send($children[$i]->id, 'signed', ['files' => ["s{$i}"]]);
        $t->drain();
    }
    $t->assertFinal('done');
    expect($t->view()->output())->toBe([['s0'], ['s1'], ['s2']]);
});

it('keeps spawned children alive after the parent ends when asked to', function () {
    $t = (new ProcessTest)->begin(Runtime\Batch::class, ['documents' => []])->assertFinal('done');
    $t2 = (new ProcessTest)->begin(Runtime\Batch::class, ['documents' => ['x']])->send('request', ['id' => 'r1']);
    $spawned = array_values(array_filter($t2->view()->children(), fn ($c) => isset($c->parentLink['spawn'])));
    $invoked = array_values(array_filter($t2->view()->children(), fn ($c) => isset($c->parentLink['invoke'])));
    $t2->engine->send($invoked[0]->id, 'signed', ['files' => []]);
    $t2->drain()->assertFinal('done');

    expect($t2->engine->get($spawned[0]->id)->status())->toBe('running');
});

it('reports a child failure to the parent as an error', function () {
    $t = (new ProcessTest)->begin(Runtime\Claim::class, ['claimId' => 'C']);
    $child = $t->view()->children()[0];
    $t->engine->operate($child->id, 'fail', [], $t->operator(), 'broken');
    $t->drain()->assertIncident('effect_failed');
});
