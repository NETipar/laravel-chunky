<?php

declare(strict_types=1);

namespace NETipar\Chunky\Tests\Fixtures;

class MetricWithDependency
{
    public function __construct(private FakeDatadogClient $datadog) {}

    /**
     * @param  array{metric: string, value: int|float}  $payload
     */
    public function __invoke(array $payload): void
    {
        $this->datadog->histogram($payload['metric'], $payload['value']);
    }
}
