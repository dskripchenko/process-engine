<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Attributes;

use Attribute;

/**
 * The item class of an array property of a context or event, so the
 * hydrator can rebuild a list of objects.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class ListOf
{
    /**
     * @param  class-string  $class
     */
    public function __construct(public readonly string $class) {}
}
