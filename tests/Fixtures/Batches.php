<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Tests\Fixtures\Batches;

use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Batch\BatchHandler;
use Dskripchenko\ProcessEngine\Batch\BatchResult;
use Dskripchenko\ProcessEngine\Batch\ItemOutcome;
use Dskripchenko\ProcessEngine\Batch\ItemSource;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\EffectFailed;
use Dskripchenko\ProcessEngine\Effect\EffectNotExecuted;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Process;

/**
 * What the handler saw and what it is told to do: shared by the fixtures of
 * one test, reset before each.
 */
final class Script
{
    /** @var list<list<string>> keys of each call */
    public static array $calls = [];

    /** @var array<string, int> key => how many more times it fails */
    public static array $flaky = [];

    /** A key whose chunk fails for certain with EffectFailed('fatal'). */
    public static ?string $fatal = null;

    /** How many more calls the service is unavailable (EffectNotExecuted). */
    public static int $down = 0;

    /** A key whose chunk is unavailable while set: keeps the batch running. */
    public static ?string $blocked = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$flaky = [];
        self::$fatal = null;
        self::$down = 0;
        self::$blocked = null;
    }

    /** @return list<string> every key handed to the handler, in order */
    public static function seen(): array
    {
        return array_merge(...(self::$calls ?: [[]]));
    }
}

/** Keys 1…n of the context, in order. */
final class Numbers implements ItemSource
{
    /** @return list<int> */
    public function keys(array $context, ?string $after, int $limit): array
    {
        $from = $after === null ? 1 : (int) $after + 1;

        return $from > (int) $context['n'] ? [] : range($from, min((int) $context['n'], $from + $limit - 1));
    }

    public function count(array $context): int
    {
        return (int) $context['n'];
    }
}

/** Every multiple of 7 is not found; flaky keys fail while they are told to. */
#[Idempotent(retry: new Retry(attempts: 3, initialInterval: '1 minute', jitter: false))]
final class Lookup implements BatchHandler
{
    /** @param list<string> $keys */
    public function handle(array $keys, Effect $fx): BatchResult
    {
        if (Script::$down > 0) {
            Script::$down--;

            throw new EffectNotExecuted(new \RuntimeException('quota'));
        }
        if (Script::$blocked !== null && in_array(Script::$blocked, $keys, true)) {
            throw new EffectNotExecuted(new \RuntimeException('blocked'));
        }
        if (Script::$fatal !== null && in_array(Script::$fatal, $keys, true)) {
            throw new EffectFailed('fatal', 'the key was revoked');
        }
        Script::$calls[] = $keys;
        $out = [];
        foreach ($keys as $key) {
            if ((Script::$flaky[$key] ?? 0) > 0) {
                Script::$flaky[$key]--;
                $out[$key] = ItemOutcome::failed('timeout');
            } elseif ((int) $key % 7 === 0) {
                $out[$key] = ItemOutcome::notFound();
            } else {
                $out[$key] = ItemOutcome::done();
            }
        }

        return BatchResult::of($out);
    }
}

#[AtMostOnce]
final class Charge implements BatchHandler
{
    public function handle(array $keys): BatchResult
    {
        return BatchResult::allDone($keys);
    }
}

final class Enrich extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('lookup', fn (Builder $s) => $s
            ->batch(Lookup::class, items: Numbers::class, chunk: 10, concurrency: 3, itemAttempts: 2,
                into: 'counters', onDone: 'done', onError: ['fatal' => 'stopped'])
            ->on('abort', to: 'aborted'));
        $p->final('done');
        $p->final('stopped');
        $p->final('aborted');
    }
}

/** A pipeline of one switchable source stage. */
final class Pipeline extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('dadata', fn (Builder $s) => $s
            ->switchable('enrichment.dadata', skipTo: 'merge', offOn: ['fatal'], label: 'DaData')
            ->batch(Lookup::class, items: Numbers::class, chunk: 10, onDone: 'merge', onError: ['fatal' => 'merge']));
        $p->state('merge', fn (Builder $s) => $s->on('finish', to: 'done'));
        $p->final('done');
    }
}
