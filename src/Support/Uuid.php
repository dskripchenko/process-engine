<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

use DateTimeInterface;

/**
 * UUID version 7 (RFC 9562): 48 bits of Unix time in milliseconds, then random
 * bits. Sortable by creation time, which keeps index inserts cheap.
 */
final class Uuid
{
    public static function v7(?DateTimeInterface $at = null): string
    {
        $ms = $at !== null
            ? (int) $at->format('Uv')
            : (int) floor(microtime(true) * 1000);

        $time = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
        $random = random_bytes(10);

        $bytes = hex2bin($time).$random;
        // Version 7 in the high nibble of byte 6, RFC variant in byte 8.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
