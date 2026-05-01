<?php

declare(strict_types=1);

use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Trackers\DatabaseTracker;
use NETipar\Chunky\Trackers\FilesystemTracker;

it('loads the default configuration', function () {
    expect(config('chunky.tracker'))->toBe('database');
    expect(config('chunky.disk'))->toBe('local');
    expect(config('chunky.chunks.size'))->toBe(1024 * 1024);
    expect(config('chunky.storage.temp_directory'))->toBe('chunky/temp');
    expect(config('chunky.storage.final_directory'))->toBe('chunky/uploads');
    expect(config('chunky.lifecycle.expiration_minutes'))->toBe(360);
    expect(config('chunky.limits.max_file_size'))->toBe(0);
    expect(config('chunky.limits.allowed_mimes'))->toBe([]);
    expect(config('chunky.chunks.verify_integrity'))->toBeTrue();
    expect(config('chunky.lifecycle.auto_cleanup'))->toBeTrue();
});

it('registers the chunky manager singleton', function () {
    $manager = app(ChunkyManager::class);

    expect($manager)->toBeInstanceOf(ChunkyManager::class);
    expect(app(ChunkyManager::class))->toBe($manager);
});

it('binds the correct tracker based on config', function () {
    config(['chunky.tracker' => 'database']);
    $this->app->forgetInstance(UploadTracker::class);
    app()->singleton(UploadTracker::class, function () {
        return match (config('chunky.tracker')) {
            'filesystem' => new FilesystemTracker,
            default => new DatabaseTracker,
        };
    });

    expect(app(UploadTracker::class))
        ->toBeInstanceOf(DatabaseTracker::class);
});

it('registers routes with configured prefix', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    $chunkyRoutes = $routes->filter(fn ($route) => str_contains($route->uri(), 'api/chunky'));

    expect($chunkyRoutes)->toHaveCount(7);
});
