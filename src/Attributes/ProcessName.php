<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Attributes;

use Attribute;

/**
 * The stored name of a process. Without it the name is the kebab-case short
 * class name (`ClaimSettlement` → `claim-settlement`).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ProcessName
{
    public function __construct(public readonly string $name) {}
}
