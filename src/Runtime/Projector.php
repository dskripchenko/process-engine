<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;

/**
 * Mirrors instance state into host data (a status column of the bound
 * model) inside the step transaction. Hosts plug one in; the core has none.
 */
interface Projector
{
    public function project(InstanceRecord $instance, Definition $definition): void;
}
