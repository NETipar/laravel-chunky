<?php

namespace NETipar\Chunky\Tests\Fixtures;

/**
 * Has neither __invoke nor handle() — Metrics::dispatch() should reject it
 * with InvalidArgumentException, which Metrics::emit then swallows.
 */
class UselessMetric
{
    public function someOtherMethod(): void {}
}
