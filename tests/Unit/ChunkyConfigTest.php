<?php

declare(strict_types=1);

use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Exceptions\InvalidConfigurationException;

/**
 * @return array<string, mixed>
 */
function baseChunkyConfig(): array
{
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/chunky.php';

    return $config;
}

it('parses the shipped default config', function () {
    $config = ChunkyConfig::fromArray(baseChunkyConfig());

    expect($config->tracker)->toBe('database');
    expect($config->disk)->toBe('local');
    expect($config->chunkSize)->toBe(5 * 1024 * 1024);
    expect($config->assemblyMode)->toBe('auto');
    expect($config->metadataMaxKeys)->toBe(50);
    expect($config->routesPrefix)->toBe('api/chunky');
    expect($config->broadcastingEnabled)->toBeFalse();
    expect($config->broadcastingExcept)->toContain(ChunkUploaded::class);
});

it('normalizes an empty-string assembly connection to null', function () {
    $raw = baseChunkyConfig();
    $raw['assembly']['connection'] = '';

    expect(ChunkyConfig::fromArray($raw)->assemblyConnection)->toBeNull();
});

it('casts a numeric-string chunk size to int (env-backed config)', function () {
    $raw = baseChunkyConfig();
    $raw['chunks']['size'] = '2097152';

    expect(ChunkyConfig::fromArray($raw)->chunkSize)->toBe(2097152);
});

it('rejects an unknown tracker', function () {
    $raw = baseChunkyConfig();
    $raw['tracker'] = 'redis';

    expect(fn () => ChunkyConfig::fromArray($raw))
        ->toThrow(InvalidConfigurationException::class, 'tracker');
});

it('rejects a non-positive chunk size', function () {
    $raw = baseChunkyConfig();
    $raw['chunks']['size'] = 0;

    expect(fn () => ChunkyConfig::fromArray($raw))
        ->toThrow(InvalidConfigurationException::class, 'chunks.size');
});

it('rejects an invalid assembly mode', function () {
    $raw = baseChunkyConfig();
    $raw['assembly']['mode'] = 'eventually';

    expect(fn () => ChunkyConfig::fromArray($raw))
        ->toThrow(InvalidConfigurationException::class, 'assembly.mode');
});

it('rejects a zero metadata_max_keys', function () {
    $raw = baseChunkyConfig();
    $raw['limits']['metadata_max_keys'] = 0;

    expect(fn () => ChunkyConfig::fromArray($raw))
        ->toThrow(InvalidConfigurationException::class, 'limits.metadata_max_keys');
});
