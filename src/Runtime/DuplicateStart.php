<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use RuntimeException;

/**
 * Another caller started an instance with the same business key first.
 */
final class DuplicateStart extends RuntimeException {}
