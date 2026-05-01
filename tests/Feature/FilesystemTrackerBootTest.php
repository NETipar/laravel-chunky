<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Trackers\FilesystemTracker;

beforeEach(function () {
    config(['chunky.tracker' => 'filesystem']);
});

/**
 * Bind a Filesystem mock under $name whose path() throws — same shape as
 * non-local Flysystem adapters (S3, GCS, in-memory).
 */
function bindNonLocalDisk(string $name): void
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('path')->andThrow(new RuntimeException('No local path on this driver.'));
    $disk->shouldIgnoreMissing();

    Storage::set($name, $disk);
}

it('boots successfully when the configured disk exposes a local path', function () {
    Storage::fake('local');
    config(['chunky.disk' => 'local']);

    expect(fn () => new FilesystemTracker)->not->toThrow(ChunkyException::class);
});

it('refuses to boot on a non-local disk under flock mode', function () {
    config(['chunky.disk' => 'fake-cloud', 'chunky.locking.driver' => 'flock']);
    bindNonLocalDisk('fake-cloud');

    expect(fn () => new FilesystemTracker)
        ->toThrow(ChunkyException::class, 'FilesystemTracker requires a local-path-capable disk');
});

it('boots successfully on a non-local disk when chunky.locking.driver is cache', function () {
    config([
        'chunky.disk' => 'fake-cloud-2',
        'chunky.locking.driver' => 'cache',
    ]);
    bindNonLocalDisk('fake-cloud-2');

    expect(fn () => new FilesystemTracker)->not->toThrow(ChunkyException::class);
});
