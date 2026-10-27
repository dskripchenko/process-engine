<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Batch;

/**
 * Where the elements of a batch come from (ADR-014): read page by page in a
 * stable order of keys, so a million elements never sit in the context.
 *
 * Implementations declare `keys()` and, optionally, `count()` with any
 * arguments resolved by type and name, like effects: the context (`array
 * $context` or its class), `?string $after`, `int $limit`; services come
 * through the constructor.
 *
 *   public function keys(array $context, ?string $after, int $limit): array  // list<string|int>; [] — no more
 *   public function count(array $context): ?int                              // for progress; null — unknown
 */
interface ItemSource {}
