<?php

declare(strict_types=1);

use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;

function sampleRecord(): UploadRecord
{
    return new UploadRecord(
        uploadId: 'up-1',
        fileName: 'video.mp4',
        fileSize: 1000,
        mimeType: 'video/mp4',
        chunkSize: 100,
        totalChunks: 10,
        disk: 's3',
        profile: 'avatar',
        metadata: ['album_id' => 7],
        uploadedChunks: [0, 1, 2, 3, 4],
        status: UploadStatus::Uploading,
        finalPath: 'chunky/uploads/up-1/video.mp4',
        batchId: 'b-1',
        userId: '42',
        fingerprint: 'fp-abc',
        expiresAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        claimedAt: new DateTimeImmutable('2026-01-01T00:05:00+00:00'),
        resultPayload: ['media_id' => 9],
    );
}

it('computes progress from uploaded chunks', function () {
    expect(sampleRecord()->progress())->toBe(50.0);
});

it('reports completion', function () {
    $record = sampleRecord();
    expect($record->isComplete())->toBeFalse();

    $full = $record->with(uploadedChunks: range(0, 9));
    expect($full->isComplete())->toBeTrue();
});

it('round-trips through toArray/fromArray', function () {
    $record = sampleRecord();
    $restored = UploadRecord::fromArray($record->toArray());

    expect($restored->uploadId)->toBe('up-1');
    expect($restored->fileName)->toBe('video.mp4');
    expect($restored->profile)->toBe('avatar');
    expect($restored->metadata)->toBe(['album_id' => 7]);
    expect($restored->uploadedChunks)->toBe([0, 1, 2, 3, 4]);
    expect($restored->status)->toBe(UploadStatus::Uploading);
    expect($restored->fingerprint)->toBe('fp-abc');
    expect($restored->expiresAt?->format('c'))->toBe('2026-01-01T00:00:00+00:00');
    expect($restored->claimedAt?->format('c'))->toBe('2026-01-01T00:05:00+00:00');
    expect($restored->resultPayload)->toBe(['media_id' => 9]);
});

it('copies on write with withStatus-style changes', function () {
    $record = sampleRecord();
    $completed = $record->with(status: UploadStatus::Completed, finalPath: 'new/path.mp4');

    expect($completed->status)->toBe(UploadStatus::Completed);
    expect($completed->finalPath)->toBe('new/path.mp4');
    // Original untouched.
    expect($record->status)->toBe(UploadStatus::Uploading);
    expect($record->finalPath)->toBe('chunky/uploads/up-1/video.mp4');
});

it('strips internal fields from the public projection', function () {
    $public = sampleRecord()->toPublicArray();

    expect($public)
        ->toHaveKey('upload_id')
        ->toHaveKey('status')
        ->toHaveKey('progress')
        ->not->toHaveKey('disk')
        ->not->toHaveKey('final_path')
        ->not->toHaveKey('user_id')
        ->not->toHaveKey('claimed_at')
        ->not->toHaveKey('expires_at')
        ->not->toHaveKey('result_payload')
        ->not->toHaveKey('fingerprint');
});

it('defaults to pending when constructed from a minimal array', function () {
    $record = UploadRecord::fromArray([
        'upload_id' => 'up-2',
        'file_name' => 'a.bin',
        'file_size' => 100,
        'chunk_size' => 10,
        'total_chunks' => 10,
        'disk' => 'local',
    ]);

    expect($record->status)->toBe(UploadStatus::Pending);
    expect($record->mimeType)->toBeNull();
    expect($record->uploadedChunks)->toBe([]);
    expect($record->expiresAt)->toBeNull();
});
