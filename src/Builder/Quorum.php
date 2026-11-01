<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

use Closure;

/**
 * Go on once `n` of the branches are done (ADR-017): `n` regions of a
 * parallel state, or `n` of the children / calls of a forEach invocation.
 * `n` is a number or a method of the process, read when the state is entered.
 */
final class Quorum
{
    public function __construct(public readonly int|Closure $n) {}
}
