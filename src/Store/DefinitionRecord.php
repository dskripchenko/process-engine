<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

/**
 * An immutable stored version of a process definition.
 */
final class DefinitionRecord
{
    /**
     * @param  array<string, mixed>  $schema  the canonical form
     */
    public function __construct(
        public string $id,
        public readonly string $name,
        public readonly int $version,
        public readonly string $hash,
        public readonly array $schema,
        public readonly DateTimeImmutable $createdAt,
    ) {}
}
