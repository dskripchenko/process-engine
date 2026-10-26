<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;

/**
 * Applies migrations to instances (filled in with the versioning stage).
 *
 * @internal
 */
interface Migrator
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function apply(InstanceRecord $instance, array $params, Actor $actor, ?string $reason): string;
}
