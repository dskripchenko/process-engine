<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use RuntimeException;

/**
 * The graph changed but the version did not: bump `VERSION`.
 */
final class DefinitionVersionConflict extends RuntimeException {}
