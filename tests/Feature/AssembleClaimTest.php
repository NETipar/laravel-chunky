<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Jobs\AssembleFileJob;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Event::fake([UploadCompleted::class]);
});

function seedClaimUpload(string $uploadId): void
{
    $tracker = app(UploadTracker::class);
    $handler = app(ChunkHandler::class);

    $chunk = UploadedFile::fake()->createWithContent('chunk_0', 'x');
    $handler->store($uploadId, 0, $chunk);

    $tracker->initiate($uploadId, new UploadMetadata(
        uploadId: $uploadId,
        fileName: 'out.bin',
        fileSize: 1,
        mimeType: null,
        chunkSize: 1024,
        totalChunks: 1,
        disk: 'local',
        context: null,
    ));
    $tracker->markChunkUploaded($uploadId, 0);
}

it('claimForAssembly transitions Pending to Assembling exactly once', function () {
    seedClaimUpload('claim-1');

    $tracker = app(UploadTracker::class);

    expect($tracker->claimForAssembly('claim-1'))->toBeTrue();
    expect($tracker->getMetadata('claim-1')->status)->toBe(UploadStatus::Assembling);

    expect($tracker->claimForAssembly('claim-1'))->toBeFalse();
});

it('second AssembleFileJob is a no-op when first one already claimed', function () {
    seedClaimUpload('claim-2');

    (new AssembleFileJob('claim-2'))->handle(
        app(ChunkHandler::class),
        app(UploadTracker::class),
        app(ChunkyManager::class),
    );

    Event::assertDispatchedTimes(UploadCompleted::class, 1);

    (new AssembleFileJob('claim-2'))->handle(
        app(ChunkHandler::class),
        app(UploadTracker::class),
        app(ChunkyManager::class),
    );

    Event::assertDispatchedTimes(UploadCompleted::class, 1);
});

it('claimForAssembly returns false for missing upload', function () {
    expect(app(UploadTracker::class)->claimForAssembly('does-not-exist'))->toBeFalse();
});
