<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Fixtures;

class FakeDatadogClient
{
    /** @var list<array{0: string, 1: int|float}> */
    public static array $received = [];

    public function histogram(string $metric, int|float $value): void
    {
        self::$received[] = [$metric, $value];
    }
}
