<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockProvider as CacheLockContract;
use Illuminate\Support\Facades\Cache;
use NETipar\Chunky\Adapters\Lock\CacheLockProvider;
use NETipar\Chunky\Adapters\Lock\FlockProvider;

it('runs the callback under a cache lock and returns its value', function () {
    /** @var CacheLockContract $store */
    $store = Cache::store('array')->getStore();
    $lock = new CacheLockProvider($store);

    expect($lock->withLock('res-1', fn (): int => 42))->toBe(42);
});

it('serializes sequential cache-lock acquisitions on the same key', function () {
    /** @var CacheLockContract $store */
    $store = Cache::store('array')->getStore();
    $lock = new CacheLockProvider($store);

    $lock->withLock('res-1', fn () => null);
    // A second acquisition on the same key must still succeed (the first
    // released), proving the lock is not leaked.
    expect($lock->withLock('res-1', fn (): string => 'again'))->toBe('again');
});

it('runs the callback under a flock and returns its value', function () {
    $dir = sys_get_temp_dir().'/chunky-flock-test';
    $lock = new FlockProvider($dir);

    expect($lock->withLock('res-1', fn (): string => 'ok'))->toBe('ok');
    expect($lock->withLock('res-1', fn (): string => 'again'))->toBe('again');
});
