<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function seedCancelUpload(string $uploadId): void
{
    $tracker = app(UploadTracker::class);
    $handler = app(ChunkHandler::class);

    $handler->store($uploadId, 0, UploadedFile::fake()->createWithContent('chunk_0', 'a'));
    $handler->store($uploadId, 1, UploadedFile::fake()->createWithContent('chunk_1', 'b'));

    $tracker->initiate($uploadId, new UploadMetadata(
        uploadId: $uploadId,
        fileName: 'cancel.bin',
        fileSize: 2,
        mimeType: null,
        chunkSize: 1,
        totalChunks: 2,
        disk: 'local',
        context: null,
    ));
    $tracker->markChunkUploaded($uploadId, 0);
}

it('cancels an in-progress upload via DELETE and removes the temp chunks', function () {
    seedCancelUpload('cancel-1');

    Storage::disk('local')->assertExists('chunky/temp/cancel-1/chunk_0');

    $response = $this->deleteJson('/api/chunky/upload/cancel-1');

    $response->assertStatus(204);
    Storage::disk('local')->assertMissing('chunky/temp/cancel-1');
    expect(app(UploadTracker::class)->getMetadata('cancel-1')->status)->toBe(UploadStatus::Cancelled);
});

it('returns 404 when cancelling an unknown upload', function () {
    $this->deleteJson('/api/chunky/upload/missing')->assertStatus(404);
});

it('returns 404 when cancelling an already completed upload', function () {
    seedCancelUpload('cancel-2');
    app(UploadTracker::class)->updateStatus('cancel-2', UploadStatus::Completed);

    $this->deleteJson('/api/chunky/upload/cancel-2')->assertStatus(404);
});

it('cancel() returns false when called twice', function () {
    seedCancelUpload('cancel-3');

    $manager = app(ChunkyManager::class);

    expect($manager->cancel('cancel-3'))->toBeTrue();
    expect($manager->cancel('cancel-3'))->toBeFalse();
});
