<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine;

/**
 * A host entity a process instance can be bound to (`start(..., subject: $claim)`).
 */
interface ProcessSubject
{
    public function processSubjectType(): string;

    public function processSubjectId(): string;
}
