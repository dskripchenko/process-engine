<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Runtime;

use RuntimeException;

final class InstanceNotFound extends RuntimeException
{
    public static function id(string $id): self
    {
        return new self("Process instance `{$id}` not found");
    }
}
