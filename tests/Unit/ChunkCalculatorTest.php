<?php

use NETipar\Chunky\Support\ChunkCalculator;

it('calculates total chunks correctly', function () {
    expect(ChunkCalculator::totalChunks(10 * 1024 * 1024, 1024 * 1024))->toBe(10);
    expect(ChunkCalculator::totalChunks(10 * 1024 * 1024 + 1, 1024 * 1024))->toBe(11);
    expect(ChunkCalculator::totalChunks(1024 * 1024, 1024 * 1024))->toBe(1);
});

it('returns chunk size from config or override', function () {
    config(['chunky.chunk_size' => 2 * 1024 * 1024]);

    expect(ChunkCalculator::chunkSize())->toBe(2 * 1024 * 1024);
    expect(ChunkCalculator::chunkSize(5 * 1024 * 1024))->toBe(5 * 1024 * 1024);
});

it('calculates progress percentage', function () {
    expect(ChunkCalculator::progress(0, 10))->toBe(0.0);
    expect(ChunkCalculator::progress(5, 10))->toBe(50.0);
    expect(ChunkCalculator::progress(10, 10))->toBe(100.0);
    expect(ChunkCalculator::progress(1, 3))->toBe(33.33);
});

it('returns zero progress when total chunks is zero', function () {
    expect(ChunkCalculator::progress(0, 0))->toBe(0.0);
});
