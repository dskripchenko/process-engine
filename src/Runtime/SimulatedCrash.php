<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use RuntimeException;

/**
 * Thrown at a fault point to simulate the process dying there.
 */
final class SimulatedCrash extends RuntimeException {}
