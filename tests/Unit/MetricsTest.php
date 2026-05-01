<?php

use NETipar\Chunky\Support\Metrics;

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
