<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Trackers\FilesystemTracker;

beforeEach(function () {
    Storage::fake('local');
});

function fsTracker(): FilesystemTracker
{
    return new FilesystemTracker;
}

function fsMetadata(string $uploadId = 'fs-test', int $totalChunks = 5): UploadMetadata
{
    return new UploadMetadata(
        uploadId: $uploadId,
        fileName: 'test.pdf',
        fileSize: 5 * 1024 * 1024,
        mimeType: 'application/pdf',
        chunkSize: 1024 * 1024,
        totalChunks: $totalChunks,
        disk: 'local',
        context: null,
    );
}

it('initiates and stores metadata as JSON file', function () {
    $tracker = fsTracker();
    $tracker->initiate('fs-test', fsMetadata());

    Storage::disk('local')->assertExists('chunky/temp/fs-test/metadata.json');
});

it('marks chunks as uploaded', function () {
    $tracker = fsTracker();
    $tracker->initiate('fs-test', fsMetadata());

    $tracker->markChunkUploaded('fs-test', 0);
    $tracker->markChunkUploaded('fs-test', 2);
    $tracker->markChunkUploaded('fs-test', 1);

    $chunks = $tracker->getUploadedChunks('fs-test');

    expect($chunks)->toBe([0, 1, 2]);
});

it('does not duplicate chunk indices', function () {
    $tracker = fsTracker();
    $tracker->initiate('fs-test', fsMetadata());

    $tracker->markChunkUploaded('fs-test', 0);
    $tracker->markChunkUploaded('fs-test', 0);

    expect($tracker->getUploadedChunks('fs-test'))->toBe([0]);
});

it('detects completion', function () {
    $tracker = fsTracker();
    $tracker->initiate('fs-test', fsMetadata(totalChunks: 2));

    $tracker->markChunkUploaded('fs-test', 0);

    expect($tracker->isComplete('fs-test'))->toBeFalse();

    $tracker->markChunkUploaded('fs-test', 1);

    expect($tracker->isComplete('fs-test'))->toBeTrue();
});

it('returns metadata', function () {
    $tracker = fsTracker();
    $tracker->initiate('fs-test', fsMetadata());

    $metadata = $tracker->getMetadata('fs-test');

    expect($metadata)->toBeInstanceOf(UploadMetadata::class);
    expect($metadata->uploadId)->toBe('fs-test');
    expect($metadata->fileName)->toBe('test.pdf');
});

it('returns null for non-existent upload', function () {
    $tracker = fsTracker();

    expect($tracker->getMetadata('nonexistent'))->toBeNull();
});

it('expires an upload', function () {
    $tracker = fsTracker();
    $tracker->initiate('fs-test', fsMetadata());

    $tracker->expire('fs-test');

    $content = json_decode(Storage::disk('local')->get('chunky/temp/fs-test/metadata.json'), true);

    expect($content['status'])->toBe('expired');
});

it('throws exception for non-existent upload on operations', function () {
    $tracker = fsTracker();

    $tracker->markChunkUploaded('nonexistent', 0);
})->throws(ChunkyException::class);

it('throws exception for expired upload', function () {
    $tracker = fsTracker();
    $tracker->initiate('fs-test', fsMetadata());

    $path = 'chunky/temp/fs-test/metadata.json';
    $content = json_decode(Storage::disk('local')->get($path), true);
    $content['expires_at'] = now()->subMinute()->toIso8601String();
    Storage::disk('local')->put($path, json_encode($content));

    $tracker->markChunkUploaded('fs-test', 0);
})->throws(UploadExpiredException::class);
