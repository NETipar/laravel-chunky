<?php

use NETipar\Chunky\Support\Metrics;
use NETipar\Chunky\Tests\Fixtures\BrokenMetric;
use NETipar\Chunky\Tests\Fixtures\FakeDatadogClient;
use NETipar\Chunky\Tests\Fixtures\HandleMetric;
use NETipar\Chunky\Tests\Fixtures\InvokableMetric;
use NETipar\Chunky\Tests\Fixtures\MetricWithDependency;
use NETipar\Chunky\Tests\Fixtures\UselessMetric;

it('invokes the configured callback with the payload', function () {
    $captured = null;

    config([
        'chunky.metrics.test_event' => function (array $payload) use (&$captured) {
            $captured = $payload;
        },
    ]);

    Metrics::emit('test_event', ['a' => 1, 'b' => 2]);

    expect($captured)->toBe(['a' => 1, 'b' => 2]);
});

it('is a no-op when no callback is configured', function () {
    config(['chunky.metrics.unset_event' => null]);

    // Must not throw.
    Metrics::emit('unset_event', ['x' => 'y']);

    expect(true)->toBeTrue();
});

it('swallows callback exceptions so observability cannot break the pipeline', function () {
    config([
        'chunky.metrics.broken' => function () {
            throw new RuntimeException('observability broke');
        },
    ]);

    // Must not bubble.
    Metrics::emit('broken', []);

    expect(true)->toBeTrue();
});

it('resolves a class-string handler through the container and calls __invoke', function () {
    config(['chunky.metrics.test_class' => InvokableMetric::class]);

    InvokableMetric::$captured = null;

    Metrics::emit('test_class', ['x' => 1]);

    expect(InvokableMetric::$captured)->toBe(['x' => 1]);
});

it('falls back to handle() on a non-invokable class', function () {
    config(['chunky.metrics.test_handle' => HandleMetric::class]);

    HandleMetric::$captured = null;

    Metrics::emit('test_handle', ['y' => 2]);

    expect(HandleMetric::$captured)->toBe(['y' => 2]);
});

it('resolves the handler each time so the container can manage its lifecycle', function () {
    config(['chunky.metrics.with_dep' => MetricWithDependency::class]);

    // Bind a fake dependency that the handler typehints.
    app()->bind(FakeDatadogClient::class, function () {
        return new FakeDatadogClient;
    });

    FakeDatadogClient::$received = [];

    Metrics::emit('with_dep', ['metric' => 'foo', 'value' => 42]);
    Metrics::emit('with_dep', ['metric' => 'bar', 'value' => 7]);

    expect(FakeDatadogClient::$received)->toBe([
        ['foo', 42],
        ['bar', 7],
    ]);
});

it('swallows class-handler exceptions just like callable ones', function () {
    config(['chunky.metrics.crashing' => BrokenMetric::class]);

    Metrics::emit('crashing', []);

    expect(true)->toBeTrue();
});

it('swallows the InvalidArgumentException when a handler class is not invokable nor has handle()', function () {
    config(['chunky.metrics.useless' => UselessMetric::class]);

    // Emit must not bubble — the dispatch error path is swallowed.
    Metrics::emit('useless', []);

    expect(true)->toBeTrue();
});
