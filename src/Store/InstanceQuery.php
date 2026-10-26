<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

/**
 * Filters for listing instances.
 */
final class InstanceQuery
{
    /**
     * @param  list<string>|null  $statuses
     */
    public function __construct(
        public readonly ?array $statuses = null,
        public readonly ?string $definitionName = null,
        public readonly ?int $definitionVersion = null,
        public readonly ?string $state = null,
        public readonly ?string $businessKey = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?bool $hasIncidents = null,
        public readonly ?string $parentId = null,
        public readonly int $limit = 100,
        public readonly int $offset = 0,
    ) {}
}
