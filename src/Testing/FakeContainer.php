<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Testing;

use Dskripchenko\ProcessEngine\Runtime\Container;

/**
 * Returns fakes for faked effect classes and real instances otherwise.
 */
final class FakeContainer implements Container
{
    /** @var array<string, FakeEffect> */
    public array $fakes = [];

    public function __construct(private readonly ?Container $fallback = null) {}

    public function get(string $class): object
    {
        if (isset($this->fakes[$class])) {
            return $this->fakes[$class];
        }

        return $this->fallback !== null ? $this->fallback->get($class) : new $class;
    }
}
