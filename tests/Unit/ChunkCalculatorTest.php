<?php

declare(strict_types=1);

use NETipar\Chunky\Support\ChunkCalculator;

it('computes total chunks with ceiling division', function () {
    expect(ChunkCalculator::totalChunks(10 * 1024 * 1024, 1024 * 1024))->toBe(10);
    expect(ChunkCalculator::totalChunks(10 * 1024 * 1024 + 1, 1024 * 1024))->toBe(11);
    expect(ChunkCalculator::totalChunks(1024 * 1024, 1024 * 1024))->toBe(1);
});

it('returns zero total chunks for a non-positive chunk size', function () {
    expect(ChunkCalculator::totalChunks(1000, 0))->toBe(0);
});

it('computes progress to two decimals', function () {
    expect(ChunkCalculator::progress(0, 10))->toBe(0.0);
    expect(ChunkCalculator::progress(5, 10))->toBe(50.0);
    expect(ChunkCalculator::progress(10, 10))->toBe(100.0);
    expect(ChunkCalculator::progress(1, 3))->toBe(33.33);
});

it('returns zero progress when total chunks is zero', function () {
    expect(ChunkCalculator::progress(0, 0))->toBe(0.0);
});
