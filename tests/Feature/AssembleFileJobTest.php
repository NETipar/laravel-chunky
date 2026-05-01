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
use NETipar\Chunky\Events\UploadFailed;
use NETipar\Chunky\Jobs\AssembleFileJob;
use NETipar\Chunky\Models\ChunkyBatch;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Event::fake([UploadCompleted::class, UploadFailed::class]);
});

function seedUpload(string $uploadId, ?string $context = null, ?string $batchId = null): UploadMetadata
{
    $tracker = app(UploadTracker::class);
    $handler = app(ChunkHandler::class);

    $chunk = UploadedFile::fake()->createWithContent('chunk_0', 'hello');
    $handler->store($uploadId, 0, $chunk);

    $metadata = new UploadMetadata(
        uploadId: $uploadId,
        fileName: 'result.bin',
        fileSize: 5,
        mimeType: null,
        chunkSize: 1024,
        totalChunks: 1,
        disk: 'local',
        context: $context,
        batchId: $batchId,
    );

    $tracker->initiate($uploadId, $metadata);
    $tracker->markChunkUploaded($uploadId, 0);

    return $metadata;
}

it('marks upload as failed and dispatches UploadFailed when save callback throws', function () {
    app(ChunkyManager::class)->context('boom', save: function () {
        throw new RuntimeException('save failed');
    });

    seedUpload('up-1', context: 'boom');

    expect(fn () => (new AssembleFileJob('up-1'))->handle(
        app(ChunkHandler::class),
        app(UploadTracker::class),
        app(ChunkyManager::class),
    ))->toThrow(RuntimeException::class, 'save failed');

    $metadata = app(UploadTracker::class)->getMetadata('up-1');

    expect($metadata->status)->toBe(UploadStatus::Failed);
    Event::assertDispatched(UploadFailed::class, fn ($e) => $e->uploadId === 'up-1' && $e->reason === 'save failed');
    Event::assertNotDispatched(UploadCompleted::class);
});

it('marks the batch as failed when save callback throws', function () {
    $batch = ChunkyBatch::create([
        'batch_id' => 'batch-1',
        'total_files' => 1,
        'completed_files' => 0,
        'failed_files' => 0,
        'expires_at' => now()->addDay(),
    ]);

    app(ChunkyManager::class)->context('boom', save: function () {
        throw new RuntimeException('nope');
    });

    seedUpload('up-2', context: 'boom', batchId: 'batch-1');

    expect(fn () => (new AssembleFileJob('up-2'))->handle(
        app(ChunkHandler::class),
        app(UploadTracker::class),
        app(ChunkyManager::class),
    ))->toThrow(RuntimeException::class);

    expect($batch->fresh()->failed_files)->toBe(1);
});

it('completes successfully when save callback succeeds', function () {
    $called = false;
    app(ChunkyManager::class)->context('ok', save: function (UploadMetadata $m) use (&$called) {
        $called = true;
        expect($m->status)->toBe(UploadStatus::Completed);
    });

    seedUpload('up-3', context: 'ok');

    (new AssembleFileJob('up-3'))->handle(
        app(ChunkHandler::class),
        app(UploadTracker::class),
        app(ChunkyManager::class),
    );

    expect($called)->toBeTrue();
    expect(app(UploadTracker::class)->getMetadata('up-3')->status)->toBe(UploadStatus::Completed);
    Event::assertDispatched(UploadCompleted::class);
    Event::assertNotDispatched(UploadFailed::class);
});

it('dispatches UploadFailed and marks batch failed in failed() callback', function () {
    $batch = ChunkyBatch::create([
        'batch_id' => 'batch-2',
        'total_files' => 1,
        'completed_files' => 0,
        'failed_files' => 0,
        'expires_at' => now()->addDay(),
    ]);

    seedUpload('up-4', batchId: 'batch-2');

    (new AssembleFileJob('up-4'))->failed(new RuntimeException('queue died'));

    $metadata = app(UploadTracker::class)->getMetadata('up-4');

    expect($metadata->status)->toBe(UploadStatus::Failed);
    expect($batch->fresh()->failed_files)->toBe(1);
    Event::assertDispatched(UploadFailed::class, fn ($e) => $e->uploadId === 'up-4' && $e->reason === 'queue died');
});

it('does not flip Completed back to Failed when failed() runs after a successful handle()', function () {
    seedUpload('up-6');

    // Simulate a successful handle(): the upload reaches Completed status.
    app(UploadTracker::class)->updateStatus('up-6', UploadStatus::Completed, 'final/path.bin');

    // Queue retries `failed()` for some unrelated post-success reason
    // (broker hiccup, dispatch threw after updateStatus). The status must
    // remain Completed and no UploadFailed event must fire.
    (new AssembleFileJob('up-6'))->failed(new RuntimeException('post-completion noise'));

    $metadata = app(UploadTracker::class)->getMetadata('up-6');

    expect($metadata->status)->toBe(UploadStatus::Completed);
    Event::assertNotDispatched(UploadFailed::class);
});

it('does not double-dispatch UploadFailed when failed() runs after handle() already failed', function () {
    app(ChunkyManager::class)->context('boom', save: function () {
        throw new RuntimeException('save failed');
    });

    seedUpload('up-5', context: 'boom');

    try {
        (new AssembleFileJob('up-5'))->handle(
            app(ChunkHandler::class),
            app(UploadTracker::class),
            app(ChunkyManager::class),
        );
    } catch (Throwable) {
    }

    (new AssembleFileJob('up-5'))->failed(new RuntimeException('save failed'));

    Event::assertDispatchedTimes(UploadFailed::class, 1);
});
