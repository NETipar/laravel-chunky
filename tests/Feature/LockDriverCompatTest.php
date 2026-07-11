<?php

declare(strict_types=1);

use NETipar\Chunky\ChunkyServiceProvider;

function rebootChunky(): void
{
    app()->getProvider(ChunkyServiceProvider::class)->boot();
}

it('rejects the cache lock driver with an in-memory array store', function () {
    config(['chunky.locking.driver' => 'cache', 'cache.default' => 'array']);

    expect(fn () => rebootChunky())
        ->toThrow(RuntimeException::class, 'atomic locks');
});

it('rejects the cache lock driver with a file store', function () {
    config(['chunky.locking.driver' => 'cache', 'cache.default' => 'file']);

    expect(fn () => rebootChunky())
        ->toThrow(RuntimeException::class, 'atomic locks');
});

it('accepts the cache lock driver with a redis store', function () {
    config(['chunky.locking.driver' => 'cache', 'cache.default' => 'redis']);

    expect(fn () => rebootChunky())->not->toThrow(RuntimeException::class);
});

it('does not check the cache driver under the default auto locking', function () {
    config(['chunky.locking.driver' => 'auto', 'cache.default' => 'array']);

    expect(fn () => rebootChunky())->not->toThrow(RuntimeException::class);
});
