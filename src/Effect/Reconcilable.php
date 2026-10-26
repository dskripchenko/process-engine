<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Effect;

/**
 * An at-most-once effect that can find out whether a call with a given
 * idempotency key went through. The method is `reconcile(...)`, its
 * arguments injected by type like `__invoke`, returning an Outcome.
 *
 * @method Outcome reconcile(mixed ...$arguments)
 */
interface Reconcilable {}
