<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;

use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Attributes\ProcessName;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Effect\Compensable;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Effect\Publish;
use Dskripchenko\ProcessEngine\Effect\Reconcilable;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\SelfValidating;

use function Dskripchenko\ProcessEngine\eq;

#[Idempotent(retry: new Retry(attempts: 3, initialInterval: '10 seconds', jitter: false))]
final class Render
{
    public function __invoke(array $context): string
    {
        return 'doc-'.($context['n'] ?? 0);
    }
}

#[Idempotent]
final class Notify
{
    public function __invoke(): void {}
}

#[AtMostOnce]
final class Charge implements Compensable, Reconcilable
{
    public function __invoke(Effect $fx): string
    {
        return 'tx-1';
    }

    public function reconcile(Effect $fx): Outcome
    {
        return Outcome::stillUnknown();
    }

    public function compensate(Effect $fx, mixed $result): void {}
}

#[AtMostOnce]
final class Transfer
{
    public function __invoke(): string
    {
        return 'ok';
    }
}

#[Idempotent]
final class Book implements Compensable
{
    public function __invoke(): string
    {
        return 'slot-1';
    }

    public function compensate(): void {}
}

#[Idempotent]
final class Upload
{
    public function __invoke(Effect $fx): string
    {
        return 'u-'.$fx->item;
    }
}

#[Idempotent]
final class CheckSignature
{
    public function __invoke(): ?string
    {
        return null;
    }
}

#[Idempotent]
final class FetchStatuses
{
    public function __invoke(array $context): array
    {
        return [Publish::to('C-1', 'status.changed', ['status' => 'paid'])];
    }
}

final class Order extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->on('cancel', to: 'cancelled', from: '*');
        $p->state('new', fn (Builder $s) => $s
            ->entry(Notify::class)
            ->on('submit', to: 'rendering', run: $this->keep(...)));
        $p->state('rendering', fn (Builder $s) => $s
            ->invoke(Render::class, into: 'document', onDone: 'paying', onError: 'renderFailed'));
        $p->state('paying', fn (Builder $s) => $s
            ->invoke(Charge::class, into: 'payment', onDone: 'booking'));
        $p->state('booking', fn (Builder $s) => $s
            ->invoke(Book::class, into: 'slot', onDone: 'uploading'));
        $p->state('uploading', fn (Builder $s) => $s
            ->invoke(Upload::class, forEach: 'files', into: 'uploaded', concurrency: 1, onDone: 'waiting'));
        $p->state('waiting', fn (Builder $s) => $s
            ->correlate('party.decision', by: 'externalId')
            ->on('party.decision', to: 'approved', when: eq('$.event.approved', true))
            ->on('party.decision', to: 'rejected')
            ->after('3 days', to: 'expired'));
        $p->state('cancelled', fn (Builder $s) => $s->compensate(onDone: 'closed'));
        $p->final('approved');
        $p->final('rejected');
        $p->final('expired');
        $p->final('renderFailed');
        $p->final('closed');
    }

    private function keep(array &$context, array $event): void
    {
        $context['externalId'] = $event['externalId'] ?? null;
        $context['files'] = $event['files'] ?? [];
    }
}

final class Signing extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('waiting', fn (Builder $s) => $s
            ->poll(CheckSignature::class, every: '1 minute')
            ->on('signed', to: 'signed', run: $this->keep(...))
            ->on('rejected', to: 'refused')
            ->after('24 hours', to: 'expired'));
        $p->final('signed', output: 'files');
        $p->final('refused');
        $p->final('expired');
    }

    private function keep(array &$context, array $event): void
    {
        $context['files'] = $event['files'] ?? [];
    }
}

final class Claim extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->on('request', spawn: Signing::class, key: 'id');
        $p->state('signing', fn (Builder $s) => $s
            ->invoke(Signing::class, into: 'signature',
                onDone: ['signed' => 'review', 'refused' => 'refused', 'expired' => 'refused']));
        $p->state('review', fn (Builder $s) => $s
            ->correlate('status.changed', by: 'claimId')
            ->poll(FetchStatuses::class, every: '1 hour')
            ->on('status.changed', to: 'paid', when: eq('$.event.status', 'paid')));
        $p->final('paid');
        $p->final('refused');
    }
}

final class Payout extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('paying', fn (Builder $s) => $s->invoke(Transfer::class, onDone: 'paid'));
        $p->final('paid');
    }
}

final class FormSubmitted implements SelfValidating
{
    public function __construct(public array $form = []) {}

    public function validate(): void
    {
        if (! isset($this->form['damage'])) {
            throw new \InvalidArgumentException('damage is required');
        }
    }
}

final class Race extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->parallel('race', fn (Builder $s) => $s
            ->region('a', fn (Builder $r) => $r
                ->state('a1', fn (Builder $s) => $s->invoke(Render::class)->on('a.done', to: 'af'))
                ->final('af'))
            ->region('b', fn (Builder $r) => $r
                ->state('b1', fn (Builder $s) => $s->invoke(Signing::class)->on('b.done', to: 'bf'))
                ->final('bf'))
            ->onDone(to: 'won'), join: 'any');
        $p->final('won');
    }
}

final class Batch extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->on('request', spawn: Signing::class, key: 'id', onScopeExit: 'keep');
        $p->state('signing', fn (Builder $s) => $s
            ->invoke(Signing::class, forEach: 'documents', into: 'signatures', onDone: 'done'));
        $p->final('done', output: 'signatures');
    }
}

#[ProcessName('versioned')]
final class VersionedV1 extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('waiting', fn (Builder $s) => $s->on('go', to: 'done')->after('2 days', to: 'late'));
        $p->final('done');
        $p->final('late');
    }
}

#[ProcessName('versioned')]
final class VersionedV2 extends Process
{
    public const VERSION = 2;

    public function define(Builder $p): void
    {
        $p->state('awaiting', fn (Builder $s) => $s->on('go', to: 'checking')->after('5 days', to: 'late'));
        $p->state('checking', fn (Builder $s) => $s->on('ok', to: 'done'));
        $p->final('done');
        $p->final('late');
    }
}

#[ProcessName('versioned')]
final class VersionedV1Changed extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('waiting', fn (Builder $s) => $s->on('go', to: 'done'));
        $p->final('done');
    }
}
