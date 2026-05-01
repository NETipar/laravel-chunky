<?php

namespace NETipar\Chunky\Tests\Fixtures;

class BrokenMetric
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(array $payload): void
    {
        throw new \RuntimeException('observability broke');
    }
}
