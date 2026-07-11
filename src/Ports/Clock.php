<?php

declare(strict_types=1);

namespace NETipar\Chunky\Ports;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
