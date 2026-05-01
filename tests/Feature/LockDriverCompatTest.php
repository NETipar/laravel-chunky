<?php

declare(strict_types=1);

use NETipar\Chunky\ChunkyServiceProvider;

/**
 * Boot guard: when chunky.lock_driver = 'cache', the cache driver must
 * actually support atomic locks. We rerun the service provider's boot()
 * after mutating config to exercise the assertion.
 */
function bootChunkyAgain(): void
{
    /** @var ChunkyServiceProvider $provider */
    $provider = app()->getProvider(ChunkyServiceProvider::class);

    $provider->boot();
}

it('rejects chunky.lock_driver=cache with cache.default=array', function () {
    config([
        'chunky.locking.driver' => 'cache',
        'cache.default' => 'array',
    ]);

    expect(fn () => bootChunkyAgain())
        ->toThrow(RuntimeException::class, 'requires a cache driver that supports atomic locks');
});

it('rejects chunky.lock_driver=cache with cache.default=file', function () {
    config([
        'chunky.locking.driver' => 'cache',
        'cache.default' => 'file',
    ]);

    expect(fn () => bootChunkyAgain())
        ->toThrow(RuntimeException::class, 'requires a cache driver that supports atomic locks');
});

it('accepts chunky.lock_driver=cache with cache.default=redis', function () {
    config([
        'chunky.locking.driver' => 'cache',
        'cache.default' => 'redis',
    ]);

    expect(fn () => bootChunkyAgain())->not->toThrow(RuntimeException::class);
});

it('does not check the cache driver under the default flock locking', function () {
    // Even with an unsafe cache driver, flock mode is fine.
    config([
        'chunky.locking.driver' => 'flock',
        'cache.default' => 'array',
    ]);

    expect(fn () => bootChunkyAgain())->not->toThrow(RuntimeException::class);
});
