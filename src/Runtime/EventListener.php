<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Receives signals after the transaction that produced them committed.
 */
interface EventListener
{
    public function handle(Signal $signal): void;
}
