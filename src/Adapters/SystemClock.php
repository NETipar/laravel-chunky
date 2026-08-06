<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters;

use DateTimeImmutable;
use NETipar\Chunky\Ports\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
