<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Models\ChunkedUpload;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function seedExpiredUpload(string $uploadId, ?Carbon $expiresAt = null): void
{
    $tracker = app(UploadTracker::class);
    $handler = app(ChunkHandler::class);

    $handler->store($uploadId, 0, UploadedFile::fake()->createWithContent('chunk_0', 'x'));

    $tracker->initiate($uploadId, new UploadMetadata(
        uploadId: $uploadId,
        fileName: 'old.bin',
        fileSize: 1,
        mimeType: null,
        chunkSize: 1024,
        totalChunks: 1,
        disk: 'local',
        context: null,
    ));

    if ($expiresAt) {
        ChunkedUpload::where('upload_id', $uploadId)->update(['expires_at' => $expiresAt]);
    }
}

it('removes expired uploads and their chunks', function () {
    seedExpiredUpload('exp-1', now()->subHour());
    seedExpiredUpload('keep-1');

    $this->artisan('chunky:cleanup')->assertExitCode(0);

    expect(ChunkedUpload::where('upload_id', 'exp-1')->exists())->toBeFalse();
    expect(ChunkedUpload::where('upload_id', 'keep-1')->exists())->toBeTrue();
    Storage::disk('local')->assertMissing('chunky/temp/exp-1');
    Storage::disk('local')->assertExists('chunky/temp/keep-1/chunk_0');
});

it('lists removable uploads in dry-run mode without deleting', function () {
    seedExpiredUpload('exp-2', now()->subDay());

    $this->artisan('chunky:cleanup --dry-run')
        ->expectsOutputToContain('Would remove: exp-2')
        ->assertExitCode(0);

    expect(ChunkedUpload::where('upload_id', 'exp-2')->exists())->toBeTrue();
    Storage::disk('local')->assertExists('chunky/temp/exp-2/chunk_0');
});

it('reports nothing to remove when no uploads have expired', function () {
    seedExpiredUpload('keep-2');

    $this->artisan('chunky:cleanup')
        ->expectsOutputToContain('No expired uploads found.')
        ->assertExitCode(0);
});
