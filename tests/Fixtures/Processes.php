<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Tests\Fixtures;

use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Event as EventName;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;
use Dskripchenko\ProcessEngine\Process;

use function Dskripchenko\ProcessEngine\eq;
use function Dskripchenko\ProcessEngine\step;

// Effects ---------------------------------------------------------------------

#[Idempotent]
final class RenderDocument
{
    public function __invoke(array $context): string
    {
        return 'doc-1';
    }
}

#[AtMostOnce]
final class ChargeCard
{
    public function __invoke(array $context, Effect $fx): string
    {
        return 'charge-'.$fx->key;
    }
}

#[Idempotent]
final class UploadFile
{
    public function __invoke(Effect $fx): string
    {
        return 'uploaded-'.$fx->item;
    }
}

#[Idempotent]
final class CheckStatus
{
    public function __invoke(): ?string
    {
        return null;
    }
}

final class NoSemantics
{
    public function __invoke(): void {}
}

// Contexts and events ---------------------------------------------------------

final class OrderContext
{
    public function __construct(
        public string $orderId = '',
        public int $requests = 0,
        public ?string $document = null,
        public ?string $reason = null,
        public array $files = [],
    ) {}
}

#[EventName('order.decided')]
final class Decided
{
    public function __construct(public bool $approved, public ?string $reason = null) {}
}

enum OrderState: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Done = 'done';
    case Cancelled = 'cancelled';
}

// Processes -------------------------------------------------------------------

final class Linear extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('a', fn (Builder $s) => $s->on('go', to: 'b'));
        $p->state('b', fn (Builder $s) => $s->on('go', to: 'c', when: $this->allowed(...))->on('go', to: 'd'));
        $p->final('c');
        $p->final('d', output: 'result');
    }

    private function allowed(array $context): bool
    {
        return ($context['allow'] ?? false) === true;
    }
}

final class Typed extends Process
{
    public const VERSION = 1;

    public const STATES = OrderState::class;

    public function define(Builder $p): void
    {
        $p->on('cancel', to: OrderState::Cancelled, from: '*');
        $p->state(OrderState::Draft, fn (Builder $s) => $s
            ->on('submit', to: OrderState::Review, run: $this->countRequest(...)));
        $p->state(OrderState::Review, fn (Builder $s) => $s
            ->on(Decided::class, to: OrderState::Done, when: $this->isApproved(...), run: $this->keep(...))
            ->on(Decided::class, to: OrderState::Draft, run: $this->keep(...)));
        $p->final(OrderState::Done);
        $p->final(OrderState::Cancelled);
    }

    private function countRequest(OrderContext $c): void
    {
        $c->requests++;
    }

    private function isApproved(Decided $e): bool
    {
        return $e->approved;
    }

    private function keep(OrderContext $c, Decided $e): void
    {
        $c->reason = $e->reason;
    }
}

final class Nested extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('work', fn (Builder $s) => $s
            ->entry(\Dskripchenko\ProcessEngine\note('enter work'))
            ->exit(\Dskripchenko\ProcessEngine\note('exit work'))
            ->on('abort', to: 'aborted')
            ->state('one', fn (Builder $s) => $s->on('next', to: 'two'))
            ->state('two', fn (Builder $s) => $s->on('next', to: 'finished'))
            ->final('finished')
            ->onDone(to: 'closed'));
        $p->final('closed');
        $p->final('aborted');
    }
}

final class Parallel extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->parallel('agreement', fn (Builder $s) => $s
            ->region('left', fn (Builder $r) => $r
                ->state('l1', fn (Builder $s) => $s->on('left.done', to: 'lf'))
                ->final('lf', output: 'leftResult'))
            ->region('right', fn (Builder $r) => $r
                ->state('r1', fn (Builder $s) => $s->on('right.done', to: 'rf'))
                ->final('rf'))
            ->onDone(to: 'done'));
        $p->final('done');
    }
}

final class ParallelAny extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->parallel('race', fn (Builder $s) => $s
            ->region('a', fn (Builder $r) => $r
                ->state('a1', fn (Builder $s) => $s->invoke(RenderDocument::class)->on('a.done', to: 'af'))
                ->final('af'))
            ->region('b', fn (Builder $r) => $r
                ->state('b1', fn (Builder $s) => $s->on('b.done', to: 'bf'))
                ->final('bf'))
            ->onDone(to: 'won'), join: 'any');
        $p->final('won');
    }
}

final class Choosing extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('start', fn (Builder $s) => $s->on('route', to: 'route'));
        $p->choice('route')->match('kind', ['money' => 'payout', 'repair' => 'service'], otherwise: 'manual');
        $p->final('payout');
        $p->final('service');
        $p->final('manual');
    }
}

final class WithHistory extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('work', fn (Builder $s) => $s
            ->on('pause', to: 'paused')
            ->state('step1', fn (Builder $s) => $s->on('next', to: 'step2'))
            ->state('step2', fn (Builder $s) => $s
                ->state('inner1', fn (Builder $s) => $s->on('deeper', to: 'inner2'))
                ->state('inner2'))
            ->history('h')
            ->history('hd', deep: true));
        $p->state('paused', fn (Builder $s) => $s
            ->on('resume', to: 'h')
            ->on('resumeDeep', to: 'hd'));
    }
}

final class Invoking extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('rendering', fn (Builder $s) => $s
            ->invoke(RenderDocument::class, into: 'document', onDone: 'charging', onError: ['broken' => 'failed', '*' => 'retrying']));
        $p->state('charging', fn (Builder $s) => $s
            ->invoke(ChargeCard::class, onDone: 'uploading', onUnknown: 'checking')
            ->after('1 hour', to: 'timedOut'));
        $p->state('uploading', fn (Builder $s) => $s
            ->invoke(UploadFile::class, forEach: 'files', into: 'uploaded', onDone: 'done'));
        $p->state('checking');
        $p->state('retrying');
        $p->final('failed');
        $p->final('timedOut');
        $p->final('done', output: $this->summary(...));
    }

    private function summary(array $context): array
    {
        return ['document' => $context['document'] ?? null, 'uploaded' => $context['uploaded'] ?? []];
    }
}

final class Signing extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('waiting', fn (Builder $s) => $s
            ->poll(CheckStatus::class, every: '1 minute')
            ->on('signed', to: 'signed')
            ->on('rejected', to: 'refused')
            ->after('24 hours', to: 'expired'));
        $p->final('signed', output: 'files');
        $p->final('refused');
        $p->final('expired');
    }
}

final class Application extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->on('request', spawn: Signing::class, key: 'id');
        $p->state('signing', fn (Builder $s) => $s
            ->invoke(Signing::class, input: $this->signingInput(...),
                onDone: ['signed' => 'registered', 'refused' => 'refused', 'expired' => 'refused']));
        $p->steps('registered', [
            step(RenderDocument::class, into: 'document'),
            step(UploadFile::class, forEach: 'files'),
        ], onDone: 'closed');
        $p->final('closed');
        $p->final('refused');
    }

    private function signingInput(array $context): array
    {
        return ['files' => $context['files'] ?? []];
    }
}

final class Looping extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('ping', fn (Builder $s) => $s->always(to: 'pong'));
        $p->state('pong', fn (Builder $s) => $s->always(to: 'ping'));
    }
}

final class Deferring extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('submitting', fn (Builder $s) => $s->defer('decision')->on('submitted', to: 'waiting'));
        $p->state('waiting', fn (Builder $s) => $s
            ->correlate('decision', by: 'externalId')
            ->on('decision', to: 'decided', when: eq('$.event.ok', true), label: 'Decide'));
        $p->final('decided');
    }
}

final class Expecting extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('agreement', fn (Builder $s) => $s
            ->meta(label: 'Sign the agreement')
            ->on('client.agreed', to: 'agreed', label: 'Sign')
            ->on('client.declined', to: 'declined', label: 'Decline')
            ->on('client.premium', to: 'agreed', when: $this->isPremium(...), label: 'Premium')
            ->on('client.amount', to: 'agreed', when: $this->amountOk(...), label: 'Amount'));
        $p->final('agreed');
        $p->final('declined');
    }

    private function isPremium(array $context): bool
    {
        return ($context['premium'] ?? false) === true;
    }

    private function amountOk(array $event): bool
    {
        return ($event['amount'] ?? 0) > 0;
    }
}

final class Timing extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('waiting', fn (Builder $s) => $s
            ->at('deadline', to: 'late')
            ->after(\Dskripchenko\ProcessEngine\businessDays(2), to: 'reminded')
            ->after($this->sla(...), to: 'escalated'));
        $p->final('late');
        $p->final('reminded');
        $p->final('escalated');
    }

    private function sla(array $context, StepContext $ctx): string
    {
        return ($context['vip'] ?? false) ? '1 hour' : '1 day';
    }
}
