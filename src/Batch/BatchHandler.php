<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Batch;

/**
 * Processes one chunk of a batch (ADR-014) and tells the outcome of every key.
 * Must be #[Idempotent]: a chunk that is retried repeats its elements.
 *
 * Declares `handle()` with arguments resolved by type and name: the context,
 * `array $keys` (list<string>), `Effect $fx`; services through the constructor.
 *
 *   public function handle(array $context, array $keys, Effect $fx): BatchResult
 *
 * Throwing EffectNotExecuted (or anything ambiguous) retries the chunk under
 * the retry policy; EffectFailed stops the whole batch with its code.
 */
interface BatchHandler {}
