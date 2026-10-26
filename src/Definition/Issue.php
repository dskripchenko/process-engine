<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Definition;

use Stringable;

/**
 * One finding of the definition validator.
 */
final class Issue implements Stringable
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $state = null,
    ) {}

    public static function error(string $code, string $message, ?string $state = null): self
    {
        return new self(self::ERROR, $code, $message, $state);
    }

    public static function warning(string $code, string $message, ?string $state = null): self
    {
        return new self(self::WARNING, $code, $message, $state);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function __toString(): string
    {
        return ($this->state !== null ? "[{$this->state}] " : '')."{$this->code}: {$this->message}";
    }
}
