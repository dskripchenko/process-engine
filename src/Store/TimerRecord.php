<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Store;

use DateTimeImmutable;

final class TimerRecord
{
    public const PENDING = 'pending';

    public const FIRED = 'fired';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $id,
        public readonly string $instanceId,
        public readonly string $key,
        public readonly string $event,
        public readonly DateTimeImmutable $fireAt,
        public readonly string $activation,
        public string $status = self::PENDING,
    ) {}
}
