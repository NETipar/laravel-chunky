<?php

use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;

it('calculates progress from uploaded chunks', function () {
    $metadata = new UploadMetadata(
        uploadId: 'test-id',
        fileName: 'file.pdf',
        fileSize: 10 * 1024 * 1024,
        mimeType: 'application/pdf',
        chunkSize: 1024 * 1024,
        totalChunks: 10,
        disk: 'local',
        context: null,
        uploadedChunks: [0, 1, 2, 3, 4],
    );

    expect($metadata->progress())->toBe(50.0);
});

it('converts to array and back', function () {
    $metadata = new UploadMetadata(
        uploadId: 'test-id',
        fileName: 'document.pdf',
        fileSize: 5242880,
        mimeType: 'application/pdf',
        chunkSize: 1048576,
        totalChunks: 5,
        disk: 'local',
        context: 'documents',
        metadata: ['folder' => 'reports'],
        uploadedChunks: [0, 1],
        status: UploadStatus::Pending,
        finalPath: null,
    );

    $array = $metadata->toArray();

    expect($array)->toHaveKeys([
        'upload_id', 'file_name', 'file_size', 'mime_type',
        'chunk_size', 'total_chunks', 'disk', 'context',
        'metadata', 'uploaded_chunks', 'status', 'final_path',
    ]);
    expect($array['upload_id'])->toBe('test-id');
    expect($array['status'])->toBe('pending');
    expect($array['metadata'])->toBe(['folder' => 'reports']);

    $restored = UploadMetadata::fromArray($array);

    expect($restored->uploadId)->toBe('test-id');
    expect($restored->fileName)->toBe('document.pdf');
    expect($restored->fileSize)->toBe(5242880);
    expect($restored->context)->toBe('documents');
    expect($restored->status)->toBe(UploadStatus::Pending);
});

it('creates a new instance with updated status via withStatus', function () {
    $metadata = new UploadMetadata(
        uploadId: 'test-id',
        fileName: 'file.pdf',
        fileSize: 5242880,
        mimeType: 'application/pdf',
        chunkSize: 1048576,
        totalChunks: 5,
        disk: 'local',
        context: 'documents',
        metadata: ['key' => 'value'],
        uploadedChunks: [0, 1, 2, 3, 4],
    );

    $completed = $metadata->withStatus(UploadStatus::Completed, 'uploads/file.pdf');

    expect($completed->status)->toBe(UploadStatus::Completed);
    expect($completed->finalPath)->toBe('uploads/file.pdf');
    expect($completed->uploadId)->toBe('test-id');
    expect($completed->fileName)->toBe('file.pdf');
    expect($completed->context)->toBe('documents');
    expect($completed->metadata)->toBe(['key' => 'value']);
    expect($completed->uploadedChunks)->toBe([0, 1, 2, 3, 4]);

    // Original unchanged
    expect($metadata->status)->toBe(UploadStatus::Pending);
    expect($metadata->finalPath)->toBeNull();
});

it('defaults to pending status when constructing from array', function () {
    $metadata = UploadMetadata::fromArray([
        'upload_id' => 'test',
        'file_name' => 'test.txt',
        'file_size' => 1000,
        'chunk_size' => 500,
        'total_chunks' => 2,
        'disk' => 'local',
    ]);

    expect($metadata->status)->toBe(UploadStatus::Pending);
    expect($metadata->mimeType)->toBeNull();
    expect($metadata->context)->toBeNull();
    expect($metadata->metadata)->toBe([]);
    expect($metadata->uploadedChunks)->toBe([]);
    expect($metadata->finalPath)->toBeNull();
});
