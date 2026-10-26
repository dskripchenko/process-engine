<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Support;

use InvalidArgumentException;

/**
 * The subset of RFC 6902 the journal needs: `add`, `remove` and `replace`
 * with RFC 6901 pointers. `diff` produces a patch between two JSON-like
 * arrays, `apply` replays one.
 */
final class JsonPatch
{
    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     * @return list<array{op: string, path: string, value?: mixed}>
     */
    public static function diff(array $before, array $after, string $base = ''): array
    {
        $ops = [];

        // Lists that changed length or order are replaced whole: index-level
        // diffs of lists are hard to read in a journal and easy to misapply.
        if ($base !== '' && (array_is_list($before) !== array_is_list($after) || (array_is_list($after) && $after !== [] && $before !== $after))) {
            return [['op' => 'replace', 'path' => $base, 'value' => $after]];
        }

        foreach ($before as $key => $value) {
            $path = $base.'/'.self::escape((string) $key);
            if (! array_key_exists($key, $after)) {
                $ops[] = ['op' => 'remove', 'path' => $path];

                continue;
            }
            $next = $after[$key];
            if (is_array($value) && is_array($next) && $value !== [] && $next !== []) {
                array_push($ops, ...self::diff($value, $next, $path));
            } elseif ($value !== $next) {
                $ops[] = ['op' => 'replace', 'path' => $path, 'value' => $next];
            }
        }
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before)) {
                $ops[] = ['op' => 'add', 'path' => $base.'/'.self::escape((string) $key), 'value' => $value];
            }
        }

        return $ops;
    }

    /**
     * @param  array<mixed>  $document
     * @param  list<array<string, mixed>>  $patch
     * @return array<mixed>
     */
    public static function apply(array $document, array $patch): array
    {
        foreach ($patch as $op) {
            $type = $op['op'] ?? null;
            $path = $op['path'] ?? null;
            if (! is_string($type) || ! is_string($path)) {
                throw new InvalidArgumentException('A patch operation needs `op` and `path`');
            }
            if ($path === '') {
                if ($type === 'remove') {
                    $document = [];
                } else {
                    $value = $op['value'] ?? [];
                    $document = is_array($value) ? $value : [];
                }

                continue;
            }
            $segments = array_map(self::unescape(...), explode('/', substr($path, 1)));
            $document = self::applyAt($document, $segments, $type, $op['value'] ?? null, $path);
        }

        return $document;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $segments
     * @return array<mixed>
     */
    private static function applyAt(array $node, array $segments, string $type, mixed $value, string $path): array
    {
        $key = array_shift($segments);
        if ($key === null) {
            return $node;
        }
        $index = array_is_list($node) && ctype_digit($key) ? (int) $key : ($key === '-' ? count($node) : $key);

        if ($segments === []) {
            switch ($type) {
                case 'add':
                    if (array_is_list($node) && is_int($index) && $index <= count($node)) {
                        array_splice($node, $index, 0, [$value]);
                    } else {
                        $node[$index] = $value;
                    }
                    break;
                case 'replace':
                    if (! array_key_exists($index, $node)) {
                        throw new InvalidArgumentException("Cannot replace missing `{$path}`");
                    }
                    $node[$index] = $value;
                    break;
                case 'remove':
                    if (! array_key_exists($index, $node)) {
                        throw new InvalidArgumentException("Cannot remove missing `{$path}`");
                    }
                    $wasList = array_is_list($node);
                    unset($node[$index]);
                    if ($wasList) {
                        $node = array_values($node);
                    }
                    break;
                default:
                    throw new InvalidArgumentException("Unsupported patch operation `{$type}`");
            }

            return $node;
        }

        $child = $node[$index] ?? null;
        if (! is_array($child)) {
            if ($type !== 'add') {
                throw new InvalidArgumentException("Path `{$path}` does not exist");
            }
            $child = [];
        }
        $node[$index] = self::applyAt($child, $segments, $type, $value, $path);

        return $node;
    }

    private static function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
