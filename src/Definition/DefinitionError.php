<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Definition;

use InvalidArgumentException;

/**
 * A definition that cannot be built or executed. `$issues` carries every
 * validation problem found, not only the first.
 */
class DefinitionError extends InvalidArgumentException
{
    /**
     * @param  list<Issue>  $issues
     */
    public function __construct(string $message, public readonly array $issues = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  list<Issue>  $issues
     */
    public static function fromIssues(string $process, array $issues): self
    {
        $errors = array_values(array_filter($issues, fn (Issue $i) => $i->isError()));
        $lines = array_map(fn (Issue $i) => '  - '.$i, $errors);

        return new self("Invalid process definition `{$process}`:\n".implode("\n", $lines), $issues);
    }
}
