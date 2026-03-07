<?php

it('loads the default configuration', function () {
    expect(config('chunky.tracker'))->toBe('database');
    expect(config('chunky.disk'))->toBe('local');
    expect(config('chunky.chunk_size'))->toBe(1024 * 1024);
    expect(config('chunky.temp_directory'))->toBe('chunky/temp');
    expect(config('chunky.final_directory'))->toBe('chunky/uploads');
    expect(config('chunky.expiration'))->toBe(1440);
    expect(config('chunky.max_file_size'))->toBe(0);
    expect(config('chunky.allowed_mimes'))->toBe([]);
    expect(config('chunky.verify_integrity'))->toBeTrue();
    expect(config('chunky.auto_cleanup'))->toBeTrue();
});

it('registers the chunky manager singleton', function () {
    $manager = app(\NETipar\Chunky\ChunkyManager::class);

    expect($manager)->toBeInstanceOf(\NETipar\Chunky\ChunkyManager::class);
    expect(app(\NETipar\Chunky\ChunkyManager::class))->toBe($manager);
});

it('binds the correct tracker based on config', function () {
    config(['chunky.tracker' => 'database']);
    $this->app->forgetInstance(\NETipar\Chunky\Contracts\UploadTracker::class);
    app()->singleton(\NETipar\Chunky\Contracts\UploadTracker::class, function () {
        return match (config('chunky.tracker')) {
            'filesystem' => new \NETipar\Chunky\Trackers\FilesystemTracker,
            default => new \NETipar\Chunky\Trackers\DatabaseTracker,
        };
    });

    expect(app(\NETipar\Chunky\Contracts\UploadTracker::class))
        ->toBeInstanceOf(\NETipar\Chunky\Trackers\DatabaseTracker::class);
});

it('registers routes with configured prefix', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    $chunkyRoutes = $routes->filter(fn ($route) => str_contains($route->uri(), 'api/chunky'));

    expect($chunkyRoutes)->toHaveCount(3);
});
