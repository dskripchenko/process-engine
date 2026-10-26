<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Builder\Compiler;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Interpreter\Command;
use Dskripchenko\ProcessEngine\Interpreter\StepContext;

require_once __DIR__.'/Fixtures/Approval.php';
require_once __DIR__.'/Fixtures/Processes.php';
require_once __DIR__.'/Fixtures/Runtime.php';
require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/Support/SchemaValidator.php';

function definition(string $class): Definition
{
    return Compiler::compile($class);
}

function ctx(string $now = '2026-10-26T10:00:00+00:00', string $instance = 'i1', int $seq = 1): StepContext
{
    return new StepContext(new DateTimeImmutable($now), $instance, $seq);
}

/**
 * @param  list<Command>  $commands
 * @return list<Command>
 */
function commandsOf(array $commands, string $type): array
{
    return array_values(array_filter($commands, fn ($c) => $c->type === $type));
}
