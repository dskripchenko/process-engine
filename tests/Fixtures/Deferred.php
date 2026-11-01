<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Tests\Fixtures\Deferred;

use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Effect\Compensable;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Effect\Reconcilable;
use Dskripchenko\ProcessEngine\Process;

use function Dskripchenko\ProcessEngine\quorum;

/** Two votes of three carry it; the third region is left. */
final class Vote extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->parallel('voting', fn (Builder $v) => $v
            ->region('a', fn (Builder $r) => $r->state('a1', fn (Builder $s) => $s->on('a.yes', to: 'af'))->final('af', output: 'a'))
            ->region('b', fn (Builder $r) => $r->state('b1', fn (Builder $s) => $s->on('b.yes', to: 'bf'))->final('bf', output: 'b'))
            ->region('c', fn (Builder $r) => $r->state('c1', fn (Builder $s) => $s->on('c.yes', to: 'cf'))->final('cf', output: 'c'))
            ->onDone(to: 'passed', run: $this->keep(...)), join: quorum(2));
        $p->final('passed');
    }

    private function keep(array &$context, array $event): void
    {
        $context['regions'] = $event['regions'] ?? null;
    }
}

/** A majority of the people asked must agree; each answer is one call. */
final class Poll extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('asking', fn (Builder $s) => $s
            ->invoke(Ask::class, forEach: 'people', into: 'answers', join: quorum($this->majority(...)), onDone: 'agreed', onError: 'failed'));
        $p->final('agreed', output: 'answers');
        $p->final('failed');
    }

    private function majority(array $context): int
    {
        return intdiv(count($context['people'] ?? []), 2) + 1;
    }
}

#[Idempotent]
final class Ask implements Compensable
{
    public function __invoke(Effect $fx): string
    {
        return 'yes-'.$fx->item;
    }

    public function compensate(Effect $fx, mixed $result): void {}
}

#[AtMostOnce]
final class Pay implements Compensable, Reconcilable
{
    public function __invoke(Effect $fx): string
    {
        return 'tx';
    }

    public function reconcile(Effect $fx): Outcome
    {
        return Outcome::stillUnknown();
    }

    public function compensate(Effect $fx, mixed $result): void {}
}

/** A payment that may come back after the customer gave up. */
final class Checkout extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('paying', fn (Builder $s) => $s
            ->invoke(Pay::class, onDone: 'paid', onOrphan: 'compensate')
            ->on('giveUp', to: 'abandoned'));
        $p->final('paid');
        $p->final('abandoned');
    }
}

/** A child that books something it can undo, then waits. */
final class Booking extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('booking', fn (Builder $s) => $s->invoke(Ask::class, onDone: 'held'));
        $p->state('held', fn (Builder $s) => $s->on('confirm', to: 'confirmed'));
        $p->final('confirmed');
    }
}

/** Leaving `waiting` undoes what the child did: the process-wide policy. */
final class Trip extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->onOrphan('compensate');
        $p->state('waiting', fn (Builder $s) => $s
            ->invoke(Booking::class, onDone: 'booked')
            ->on('cancel', to: 'cancelled'));
        $p->final('booked');
        $p->final('cancelled');
    }
}

#[AtMostOnce]
final class Plain
{
    public function __invoke(): void {}
}
