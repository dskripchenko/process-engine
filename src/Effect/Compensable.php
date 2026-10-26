<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

/**
 * An effect that can be undone. The method is `compensate(...)`, its
 * arguments injected by type like `__invoke`; a parameter named `$result`
 * receives what the original call returned. Compensation is retried until it
 * succeeds, then raises an incident.
 *
 * @method void compensate(mixed ...$arguments)
 */
interface Compensable {}
