<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Tests\Support;

/**
 * The subset of JSON Schema 2020-12 the @dskripchenko/flow schemas use:
 * type, $ref (local), properties, required, items, enum, const,
 * additionalProperties, minLength, minimum, maximum, oneOf.
 */
final class SchemaValidator
{
    /** @var array<string, mixed> */
    private array $root;

    public function __construct(string $file)
    {
        $this->root = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string> errors with JSON pointers
     */
    public function validate(mixed $value): array
    {
        $value = json_decode((string) json_encode($value), false);

        return $this->check($value, $this->root, '');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function check(mixed $value, array $schema, string $path): array
    {
        if (isset($schema['$ref'])) {
            return $this->check($value, $this->resolve((string) $schema['$ref']), $path);
        }
        $errors = [];
        if (isset($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $option) {
                if ($this->check($value, $option, $path) === []) {
                    $matches++;
                }
            }
            if ($matches !== 1) {
                $errors[] = "{$path}: matches {$matches} of oneOf";
            }
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ($types as $type) {
                $ok = $ok || match ($type) {
                    'object' => $value instanceof \stdClass,
                    'array' => is_array($value),
                    'string' => is_string($value),
                    'number' => is_int($value) || is_float($value),
                    'integer' => is_int($value),
                    'boolean' => is_bool($value),
                    'null' => $value === null,
                    default => false,
                };
            }
            if (! $ok) {
                return ["{$path}: expected ".implode('|', $types).', got '.get_debug_type($value)];
            }
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path}: expected const ".json_encode($schema['const']);
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: not in enum ".json_encode($schema['enum']);
        }
        if (isset($schema['minLength']) && is_string($value) && mb_strlen($value) < $schema['minLength']) {
            $errors[] = "{$path}: shorter than {$schema['minLength']}";
        }
        if (isset($schema['minimum']) && (is_int($value) || is_float($value)) && $value < $schema['minimum']) {
            $errors[] = "{$path}: below minimum";
        }
        if (isset($schema['maximum']) && (is_int($value) || is_float($value)) && $value > $schema['maximum']) {
            $errors[] = "{$path}: above maximum";
        }
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                array_push($errors, ...$this->check($item, $schema['items'], "{$path}/{$i}"));
            }
        }
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $props)) {
                    $errors[] = "{$path}: missing `{$required}`";
                }
            }
            foreach ($props as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    array_push($errors, ...$this->check($item, $schema['properties'][$key], "{$path}/{$key}"));
                } elseif (array_key_exists('additionalProperties', $schema)) {
                    $extra = $schema['additionalProperties'];
                    if ($extra === false) {
                        $errors[] = "{$path}: unexpected `{$key}`";
                    } elseif (is_array($extra)) {
                        array_push($errors, ...$this->check($item, $extra, "{$path}/{$key}"));
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $ref): array
    {
        if ($ref === '#') {
            return $this->root;
        }
        $node = $this->root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $node = $node[$segment];
        }

        return $node;
    }
}
