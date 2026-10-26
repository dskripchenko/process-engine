<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Attributes;

use Attribute;

/**
 * A stable external name for an event class (webhooks, renames). Without it
 * the event is named by its fully qualified class name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Event
{
    public function __construct(public readonly string $name) {}
}
