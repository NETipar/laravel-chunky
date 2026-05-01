<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Models\ChunkedUpload;
use NETipar\Chunky\Trackers\DatabaseTracker;

function createTracker(): DatabaseTracker
{
    return new DatabaseTracker;
}

function createMetadata(string $uploadId = 'test-upload', int $totalChunks = 5): UploadMetadata
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

it('initiates an upload and creates a database record', function () {
    $tracker = createTracker();
    $metadata = createMetadata();

    $tracker->initiate('test-upload', $metadata);

    $record = ChunkedUpload::where('upload_id', 'test-upload')->first();

    expect($record)->not->toBeNull();
    expect($record->file_name)->toBe('test.pdf');
    expect($record->file_size)->toBe(5 * 1024 * 1024);
    expect($record->status)->toBe(UploadStatus::Pending);
    expect($record->uploaded_chunks)->toBe([]);
});

it('marks chunks as uploaded', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata());

    $tracker->markChunkUploaded('test-upload', 0);
    $tracker->markChunkUploaded('test-upload', 2);
    $tracker->markChunkUploaded('test-upload', 1);

    $chunks = $tracker->getUploadedChunks('test-upload');

    expect($chunks)->toBe([0, 1, 2]);
});

it('returns the freshly updated metadata from markChunkUploaded', function () {
    $tracker = createTracker();
    $tracker->initiate('mark-1', createMetadata(totalChunks: 3));

    $metadata = $tracker->markChunkUploaded('mark-1', 1);

    expect($metadata->uploadedChunks)->toBe([1]);
    expect($metadata->totalChunks)->toBe(3);
    expect($metadata->uploadId)->toBe('mark-1');
});

it('does not duplicate chunk indices', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata());

    $tracker->markChunkUploaded('test-upload', 0);
    $tracker->markChunkUploaded('test-upload', 0);

    $chunks = $tracker->getUploadedChunks('test-upload');

    expect($chunks)->toBe([0]);
});

it('detects completion when all chunks uploaded', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata(totalChunks: 3));

    $tracker->markChunkUploaded('test-upload', 0);
    $tracker->markChunkUploaded('test-upload', 1);

    expect($tracker->isComplete('test-upload'))->toBeFalse();

    $tracker->markChunkUploaded('test-upload', 2);

    expect($tracker->isComplete('test-upload'))->toBeTrue();
});

it('returns metadata', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata());

    $metadata = $tracker->getMetadata('test-upload');

    expect($metadata)->toBeInstanceOf(UploadMetadata::class);
    expect($metadata->uploadId)->toBe('test-upload');
    expect($metadata->fileName)->toBe('test.pdf');
    expect($metadata->status)->toBe(UploadStatus::Pending);
});

it('returns null metadata for non-existent upload', function () {
    $tracker = createTracker();

    expect($tracker->getMetadata('nonexistent'))->toBeNull();
});

it('expires an upload', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata());

    $tracker->expire('test-upload');

    $record = ChunkedUpload::where('upload_id', 'test-upload')->first();

    expect($record->status)->toBe(UploadStatus::Expired);
});

it('updates status and final path', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata());

    $tracker->updateStatus('test-upload', UploadStatus::Completed, 'chunky/uploads/test-upload/test.pdf');

    $record = ChunkedUpload::where('upload_id', 'test-upload')->first();

    expect($record->status)->toBe(UploadStatus::Completed);
    expect($record->final_path)->toBe('chunky/uploads/test-upload/test.pdf');
    expect($record->completed_at)->not->toBeNull();
});

it('throws exception for non-existent upload on operations', function () {
    $tracker = createTracker();

    $tracker->markChunkUploaded('nonexistent', 0);
})->throws(ChunkyException::class);

it('throws exception for expired upload', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata());

    ChunkedUpload::where('upload_id', 'test-upload')->update([
        'expires_at' => now()->subMinute(),
    ]);

    $tracker->markChunkUploaded('test-upload', 0);
})->throws(UploadExpiredException::class);

it('wraps markChunkUploaded in a database transaction to prevent racing writes', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata());

    $transactionBegan = false;
    DB::listen(function ($query) use (&$transactionBegan): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $transactionBegan = true;
        }
    });
    DB::beforeExecuting(function ($query) use (&$transactionBegan): void {
        if (str_contains(strtolower($query), 'for update')) {
            $transactionBegan = true;
        }
    });

    DB::connection()->beforeStartingTransaction(function () use (&$transactionBegan): void {
        $transactionBegan = true;
    });

    $tracker->markChunkUploaded('test-upload', 0);

    expect($transactionBegan)
        ->toBeTrue('markChunkUploaded must begin a transaction to atomically read-modify-write uploaded_chunks');
});

it('persists every chunk index when markChunkUploaded is called repeatedly with the same uploadId', function () {
    $tracker = createTracker();
    $tracker->initiate('test-upload', createMetadata(totalChunks: 4));

    $tracker->markChunkUploaded('test-upload', 0);
    $tracker->markChunkUploaded('test-upload', 1);
    $tracker->markChunkUploaded('test-upload', 2);
    $tracker->markChunkUploaded('test-upload', 3);

    expect($tracker->getUploadedChunks('test-upload'))->toBe([0, 1, 2, 3]);
    expect($tracker->isComplete('test-upload'))->toBeTrue();
});
