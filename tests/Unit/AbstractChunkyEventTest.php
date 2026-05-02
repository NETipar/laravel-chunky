<?php

declare(strict_types=1);

use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Events\BatchCancelled;
use NETipar\Chunky\Events\BatchCompleted;
use NETipar\Chunky\Events\BatchInitiated;
use NETipar\Chunky\Events\BatchPartiallyCompleted;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\UploadFailed;

it('respects DEFAULT_BROADCAST_EVENTS when published config omits the events map', function () {
    // Simulate a host app that upgraded from pre-0.18 chunky and kept
    // its old `config/chunky.php` shape — broadcasting.enabled=true,
    // but no `broadcasting.events` sub-map at all. Laravel's
    // `mergeConfigFrom` only merges top-level keys, so the published
    // `broadcasting` array nukes the whole sub-tree.
    config([
        'chunky.broadcasting' => [
            'enabled' => true,
            'channel_prefix' => 'chunky',
            'queue' => null,
        ],
    ]);

    // Completion events default-on.
    expect((new UploadCompleted(makeUploadMetadata()))->broadcastWhen())->toBeTrue();
    expect((new UploadFailed(makeUploadMetadata(), 'oops'))->broadcastWhen())->toBeTrue();
    expect((new BatchCompleted('b-1', 1))->broadcastWhen())->toBeTrue();
    expect((new BatchPartiallyCompleted('b-1', 1, 1, 2))->broadcastWhen())->toBeTrue();
    expect((new BatchCancelled('b-1'))->broadcastWhen())->toBeTrue();

    // High-frequency events default-off.
    expect((new ChunkUploaded('u-1', 0, 1))->broadcastWhen())->toBeFalse();
    expect((new BatchInitiated('b-1', 1))->broadcastWhen())->toBeFalse();
});

it('respects an explicit override in the events map', function () {
    config([
        'chunky.broadcasting' => [
            'enabled' => true,
            'channel_prefix' => 'chunky',
            'queue' => null,
            'events' => [
                'UploadCompleted' => false, // explicit off
                'ChunkUploaded' => true,    // explicit on
            ],
        ],
    ]);

    expect((new UploadCompleted(makeUploadMetadata()))->broadcastWhen())->toBeFalse();
    expect((new ChunkUploaded('u-1', 0, 1))->broadcastWhen())->toBeTrue();
});

it('returns false when broadcasting is globally disabled, regardless of events map', function () {
    config([
        'chunky.broadcasting' => [
            'enabled' => false,
            'events' => ['UploadCompleted' => true],
        ],
    ]);

    expect((new UploadCompleted(makeUploadMetadata()))->broadcastWhen())->toBeFalse();
});

function makeUploadMetadata(): UploadMetadata
{
    return new UploadMetadata(
        uploadId: 'u-1',
        fileName: 'a.bin',
        fileSize: 1024,
        mimeType: null,
        chunkSize: 1024,
        totalChunks: 1,
        disk: 'local',
    );
}
