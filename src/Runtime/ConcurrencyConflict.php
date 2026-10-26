<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use RuntimeException;

/**
 * The instance changed under the worker (its lease expired and someone else
 * stepped it): the step is rolled back.
 */
final class ConcurrencyConflict extends RuntimeException {}
