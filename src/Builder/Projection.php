<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Builder;

/**
 * Mirror the current state into a column of the host model the instance is
 * bound to (written by the host adapter in the step transaction).
 */
final class Projection
{
    /**
     * @param  array<string, string|int|\BackedEnum>  $map  state id → stored value
     */
    public function __construct(
        public readonly string $model,
        public readonly string $column,
        public readonly array $map,
        public readonly ?string $using = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $map = [];
        foreach ($this->map as $state => $value) {
            $map[(string) $state] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return ['model' => $this->model, 'column' => $this->column, 'using' => $this->using, 'map' => $map];
    }
}
