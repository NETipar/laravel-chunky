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

it('rebuilds ChunkyConfig from the current config on each resolution', function () {
    config(['chunky.chunks.size' => 4096]);

    expect(app(ChunkyConfig::class)->chunkSize)->toBe(4096);
});
