<?php

declare(strict_types=1);

/**
 * The reference scenario: settling an insurance claim. Domain names live
 * here, in tests and docs only. The process is the one shown in
 * docs/internal/client-api.md (section 2).
 */

namespace Dskripchenko\ProcessEngine\Tests\Scenarios\ClaimSettlement;

use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Event;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Attributes\ProcessName;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Effect\Compensable;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Effect\Reconcilable;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\SelfValidating;

use function Dskripchenko\ProcessEngine\project;

// Context ---------------------------------------------------------------------

enum Compensation: string
{
    case Money = 'money';
    case Repair = 'repair';
}

final class ClaimContext
{
    public function __construct(
        public string $policyId,
        public array $application = [],
        public ?string $applicationDocId = null,
        public ?string $externalId = null,
        public int $documentRequests = 0,
        public ?Compensation $compensation = null,
        public ?string $slotId = null,
        public ?string $payoutId = null,
        public ?string $rejectionReason = null,
        public ?string $archiveId = null,
    ) {}
}

// Events ----------------------------------------------------------------------

final class FormSubmitted implements SelfValidating
{
    public function __construct(public array $form) {}

    public function validate(): void
    {
        if (($this->form['damage'] ?? '') === '') {
            throw new \InvalidArgumentException('Describe the damage');
        }
    }
}

#[Event('party.decision')]
final class PartyDecision
{
    public function __construct(public bool $approved, public ?string $reason = null) {}
}

#[Event('party.documents-requested')]
final class DocumentsRequested
{
    public function __construct(public array $documents) {}
}

// The other side (a fake insurer system shared by the effects) ----------------

final class InsurerSystem
{
    /** @var array<string, string> idempotency key → application id */
    public array $applications = [];

    /** @var array<string, string> */
    public array $transfers = [];

    /** @var list<string> */
    public array $refunds = [];

    /** @var list<string> */
    public array $cancelledBookings = [];

    /** @var list<string> */
    public array $messages = [];
}

// Effects ---------------------------------------------------------------------

#[Idempotent]
final class RenderApplication
{
    public function __invoke(ClaimContext $claim): string
    {
        return 'doc-'.$claim->policyId;
    }
}

#[AtMostOnce]
final class SubmitToInsurer implements Reconcilable
{
    public function __construct(private readonly InsurerSystem $insurer) {}

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        return $this->insurer->applications[$fx->key] ??= 'APP-'.(count($this->insurer->applications) + 1);
    }

    public function reconcile(ClaimContext $claim, Effect $fx): Outcome
    {
        $found = $this->insurer->applications[$fx->key] ?? null;

        return $found !== null ? Outcome::succeeded($found) : Outcome::notExecuted();
    }
}

#[Idempotent]
final class SendDocuments
{
    public function __construct(private readonly InsurerSystem $insurer) {}

    public function __invoke(ClaimContext $claim): void
    {
        $this->insurer->messages[] = "documents for {$claim->externalId}";
    }
}

#[Idempotent]
final class ReportMissingDocuments
{
    public function __construct(private readonly InsurerSystem $insurer) {}

    public function __invoke(ClaimContext $claim): void
    {
        $this->insurer->messages[] = "no documents for {$claim->externalId}";
    }
}

#[Idempotent]
final class SendCompensationOffer
{
    public function __construct(private readonly InsurerSystem $insurer) {}

    public function __invoke(ClaimContext $claim): void
    {
        $this->insurer->messages[] = 'offer';
    }
}

#[Idempotent]
final class BookService implements Compensable
{
    public function __construct(private readonly InsurerSystem $insurer) {}

    public function __invoke(ClaimContext $claim): string
    {
        return 'booking-'.$claim->slotId;
    }

    public function compensate(mixed $result): void
    {
        $this->insurer->cancelledBookings[] = (string) $result;
    }
}

#[AtMostOnce(retry: new Retry(attempts: 3, onExhausted: 'incident'))]
final class PayOut implements Compensable, Reconcilable
{
    public function __construct(private readonly InsurerSystem $insurer) {}

    public function __invoke(ClaimContext $claim, Effect $fx): string
    {
        return $this->insurer->transfers[$fx->key] ??= 'TR-'.$claim->policyId;
    }

    public function reconcile(Effect $fx): Outcome
    {
        $found = $this->insurer->transfers[$fx->key] ?? null;

        return $found !== null ? Outcome::succeeded($found) : Outcome::stillUnknown();
    }

    public function compensate(mixed $result): void
    {
        $this->insurer->refunds[] = (string) $result;
    }
}

#[Idempotent]
final class ArchiveCase
{
    public function __invoke(ClaimContext $claim): string
    {
        return 'archive-'.$claim->externalId;
    }
}

// The process -----------------------------------------------------------------

final class ClaimSettlement extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->on('client.cancelled', to: 'cancelling', from: ['application', 'review', 'agreement']);

        $p->state('application', fn (Builder $s) => $s
            ->meta(label: 'Filling in the application')
            ->after('14 days', to: 'abandoned')
            ->state('filling', fn (Builder $s) => $s
                ->on(FormSubmitted::class, to: 'signing', run: $this->storeForm(...)))
            ->state('signing', fn (Builder $s) => $s
                ->invoke(RenderApplication::class, into: 'applicationDocId')
                ->on('client.edit', to: 'filling')
                ->on('client.signed', to: 'review', label: 'Sign')));

        $p->state('review', fn (Builder $s) => $s
            ->meta(label: 'Under review')
            ->state('submitting', fn (Builder $s) => $s
                ->invoke(SubmitToInsurer::class, into: 'externalId', onDone: 'awaitingParty')
                ->defer(PartyDecision::class))
            ->state('awaitingParty', fn (Builder $s) => $s
                // The insurer's number is known only after submitting: subscribe here, not on `review`.
                ->correlate([PartyDecision::class, DocumentsRequested::class], by: 'externalId')
                ->on(DocumentsRequested::class, to: 'awaitingDocuments', run: $this->countRequest(...))
                ->on(PartyDecision::class, to: 'approved', when: $this->isApproved(...))
                ->on(PartyDecision::class, to: 'rejected', run: $this->storeRejection(...)))
            ->state('awaitingDocuments', fn (Builder $s) => $s
                ->meta(label: 'Upload the documents')
                ->defer(PartyDecision::class)
                ->on('client.documents-uploaded', to: 'awaitingParty', run: SendDocuments::class, label: 'Upload')
                ->after('10 days', to: 'awaitingParty', run: ReportMissingDocuments::class))
            ->final('approved')
            ->onDone(to: 'agreement'));

        $p->parallel('agreement', fn (Builder $s) => $s
            ->region('compensationRegion', fn (Builder $r) => $r
                ->state('offering', fn (Builder $s) => $s
                    ->entry(SendCompensationOffer::class)
                    ->on('client.accepted', to: 'agreed', run: $this->storeCompensation(...))
                    ->on('client.counter-offer', to: 'offering'))
                ->final('agreed'))
            ->region('serviceRegion', fn (Builder $r) => $r
                ->state('choosingSlot', fn (Builder $s) => $s
                    ->on('client.slot-chosen', to: 'booking', run: $this->storeSlot(...)))
                ->state('booking', fn (Builder $s) => $s
                    ->invoke(BookService::class, onDone: 'booked', onError: 'choosingSlot'))
                ->final('booked'))
            ->onDone(to: 'route'));

        $p->choice('route')
            ->when($this->isMoney(...), 'payout')
            ->otherwise('finalizing');

        $p->state('payout', fn (Builder $s) => $s
            ->meta(label: 'Paying out')
            ->invoke(PayOut::class, into: 'payoutId', onDone: 'finalizing'));

        $p->state('finalizing', fn (Builder $s) => $s
            ->invoke(ArchiveCase::class, into: 'archiveId', onDone: 'closed'));

        $p->state('cancelling', fn (Builder $s) => $s
            ->compensate(onDone: 'cancelled'));

        $p->final('closed', meta: ['label' => 'Settled']);
        $p->final('rejected', meta: ['label' => 'Rejected']);
        $p->final('abandoned');
        $p->final('cancelled', meta: ['label' => 'Cancelled']);
    }

    public function projections(): array
    {
        return [project('App\\Models\\Claim', 'status', ['filling' => 'draft', 'review' => 'review', 'closed' => 'settled'])];
    }

    private function storeForm(ClaimContext $c, FormSubmitted $e): void
    {
        $c->application = $e->form;
    }

    private function countRequest(ClaimContext $c): void
    {
        $c->documentRequests++;
    }

    private function isApproved(PartyDecision $e): bool
    {
        return $e->approved;
    }

    private function storeRejection(ClaimContext $c, PartyDecision $e): void
    {
        $c->rejectionReason = $e->reason;
    }

    private function storeCompensation(ClaimContext $c, array $e): void
    {
        $c->compensation = Compensation::from((string) ($e['type'] ?? 'repair'));
    }

    private function storeSlot(ClaimContext $c, array $e): void
    {
        $c->slotId = (string) ($e['slotId'] ?? '');
    }

    private function isMoney(ClaimContext $c): bool
    {
        return $c->compensation === Compensation::Money;
    }
}

// Version 2 adds a fraud check before the agreement (for the migration scenario).

#[ProcessName('claim-settlement')]
final class ClaimSettlementV2 extends Process
{
    public const VERSION = 2;

    public function define(Builder $p): void
    {
        $p->state('review', fn (Builder $s) => $s
            ->correlate([PartyDecision::class], by: 'externalId')
            ->state('awaitingParty', fn (Builder $s) => $s
                ->on(PartyDecision::class, to: 'fraudCheck', when: $this->isApproved(...))
                ->on(PartyDecision::class, to: 'rejected')));
        $p->state('fraudCheck', fn (Builder $s) => $s->on('fraud.cleared', to: 'closed'));
        $p->final('closed');
        $p->final('rejected');
    }

    private function isApproved(PartyDecision $e): bool
    {
        return $e->approved;
    }
}
