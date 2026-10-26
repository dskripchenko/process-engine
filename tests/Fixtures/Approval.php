<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Tests\Fixtures;

use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Process;

#[Idempotent]
final class NotifyApprover
{
    public function __invoke(array $context): void {}
}

final class DocumentApproval extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('pending', fn (Builder $s) => $s
            ->entry(NotifyApprover::class)
            ->after('2 days', run: NotifyApprover::class)
            ->after('5 days', to: 'escalated')
            ->on('approved', to: 'done')
            ->on('rejected', to: 'declined', run: $this->keepReason(...)));

        $p->state('escalated', fn (Builder $s) => $s
            ->on('approved', to: 'done')
            ->on('rejected', to: 'declined', run: $this->keepReason(...)));

        $p->final('done');
        $p->final('declined');
    }

    private function keepReason(array &$context, array $event): void
    {
        $context['reason'] = $event['reason'] ?? null;
    }
}
