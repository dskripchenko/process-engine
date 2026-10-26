<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use RuntimeException;

/**
 * Eventless transitions kept firing past the limit: the step is rejected and
 * the runtime raises an incident instead of looping forever.
 */
final class MicrostepLimitExceeded extends RuntimeException {}
