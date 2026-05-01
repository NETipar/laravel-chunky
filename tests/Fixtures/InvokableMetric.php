<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Fixtures;

class InvokableMetric
{
    /** @var array<string, mixed>|null */
    public static ?array $captured = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(array $payload): void
    {
        self::$captured = $payload;
    }
}
