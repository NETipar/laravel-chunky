<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Fixtures;

use NETipar\Chunky\Support\MetricsListener;

class HandleMetric implements MetricsListener
{
    /** @var array<string, mixed>|null */
    public static ?array $captured = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        self::$captured = $payload;
    }
}
