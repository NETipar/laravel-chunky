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
use NETipar\Chunky\Models\ChunkedUpload;

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

it('claimForAssembly takes over a stale Assembling claim after the configured threshold', function () {
    seedClaimUpload('stale-1');
    $tracker = app(UploadTracker::class);

    // First worker claims and then "crashes" — status stays Assembling.
    expect($tracker->claimForAssembly('stale-1'))->toBeTrue();
    expect($tracker->claimForAssembly('stale-1'))->toBeFalse();

    // Simulate the worker crashed long enough ago that the row is now stale.
    config(['chunky.assembly_stale_after_minutes' => 1]);
    ChunkedUpload::where('upload_id', 'stale-1')->update([
        'updated_at' => now()->subMinutes(5),
    ]);

    // A retrying worker can now reclaim and assemble.
    expect($tracker->claimForAssembly('stale-1'))->toBeTrue();
});

it('AssembleFileJob retried after a simulated crash recovers and emits UploadCompleted', function () {
    seedClaimUpload('crash-1');
    $tracker = app(UploadTracker::class);

    // First worker grabs the claim and dies before doing the work.
    expect($tracker->claimForAssembly('crash-1'))->toBeTrue();

    // Without the takeover guard, a queue retry would no-op forever.
    // Make the previous claim look stale.
    config(['chunky.assembly_stale_after_minutes' => 1]);
    ChunkedUpload::where('upload_id', 'crash-1')->update([
        'updated_at' => now()->subMinutes(5),
    ]);

    (new AssembleFileJob('crash-1'))->handle(
        app(ChunkHandler::class),
        app(UploadTracker::class),
        app(ChunkyManager::class),
    );

    expect(app(UploadTracker::class)->getMetadata('crash-1')->status)
        ->toBe(UploadStatus::Completed);
    Event::assertDispatched(UploadCompleted::class, fn ($e) => $e->uploadId === 'crash-1');
});

it('expiredUploadIds includes stale Assembling rows but skips fresh ones', function () {
    $tracker = app(UploadTracker::class);

    seedClaimUpload('fresh-assembling');
    seedClaimUpload('stale-assembling');

    // Both claimed.
    $tracker->claimForAssembly('fresh-assembling');
    $tracker->claimForAssembly('stale-assembling');

    // Both expired by their TTL.
    config(['chunky.assembly_stale_after_minutes' => 1]);
    ChunkedUpload::query()->update(['expires_at' => now()->subDay()]);

    // Only the stale one has an old updated_at.
    ChunkedUpload::where('upload_id', 'stale-assembling')->update([
        'updated_at' => now()->subMinutes(5),
    ]);
    ChunkedUpload::where('upload_id', 'fresh-assembling')->update([
        'updated_at' => now(),
    ]);

    $expired = $tracker->expiredUploadIds();

    expect($expired)->toContain('stale-assembling');
    expect($expired)->not->toContain('fresh-assembling');
});
