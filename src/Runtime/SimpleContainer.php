<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

/**
 * Instantiates classes without arguments, or returns registered instances.
 */
final class SimpleContainer implements Container
{
    /** @var array<string, object|\Closure(): object> */
    private array $bindings = [];

    public function set(string $class, object $instance): void
    {
        $this->bindings[$class] = $instance;
    }

    public function get(string $class): object
    {
        $binding = $this->bindings[$class] ?? null;
        if ($binding instanceof \Closure) {
            $object = $binding();
        } elseif ($binding !== null) {
            $object = $binding;
        } else {
            $object = new $class;
        }

        return $object;
    }
}
