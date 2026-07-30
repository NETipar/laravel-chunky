<?php

declare(strict_types=1);

use NETipar\Chunky\Config\ChunkyConfig;

it('binds a validated ChunkyConfig', function () {
    expect(app(ChunkyConfig::class))->toBeInstanceOf(ChunkyConfig::class);
    expect(app(ChunkyConfig::class)->tracker)->toBe('database');
});

it('registers the ten chunky routes under the configured prefix', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'api/chunky'));

    expect($routes)->toHaveCount(10);
});
