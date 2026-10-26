<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

/**
 * JSON encoding with the flags the engine relies on everywhere: errors throw,
 * floats keep their fraction, slashes and unicode stay readable.
 */
final class Json
{
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<mixed>
     */
    public static function decodeArray(string $json): array
    {
        $value = self::decode($json);

        return is_array($value) ? $value : [];
    }

    /**
     * Canonical form for hashing: object keys sorted recursively, lists kept in order.
     */
    public static function canonical(mixed $value): string
    {
        return self::encode(self::sortKeys($value));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeys($item);
        }

        return $value;
    }
}
