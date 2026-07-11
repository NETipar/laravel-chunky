<?php

declare(strict_types=1);

use NETipar\Chunky\Config\ChunkyConfig;

it('binds a validated ChunkyConfig from the merged config', function () {
    $config = app(ChunkyConfig::class);

    expect($config)->toBeInstanceOf(ChunkyConfig::class);
    expect($config->tracker)->toBe('database');
    expect($config->routesPrefix)->toBe('api/chunky');
    expect($config->metadataMaxKeys)->toBe(50);
});

it('resolves ChunkyConfig as a singleton', function () {
    expect(app(ChunkyConfig::class))->toBe(app(ChunkyConfig::class));
});
