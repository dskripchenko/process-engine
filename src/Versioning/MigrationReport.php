<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Versioning;

/**
 * What a migration did (or, in a dry run, would do).
 */
final class MigrationReport
{
    /**
     * @param  list<string>  $eligible  instances that qualify
     * @param  array<string, string>  $skipped  instance → why not
     */
    public function __construct(
        public readonly string $migration,
        public readonly bool $dryRun,
        public readonly array $eligible = [],
        public readonly array $skipped = [],
    ) {}
}
