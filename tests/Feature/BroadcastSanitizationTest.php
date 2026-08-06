<?php

declare(strict_types=1);

use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\UploadFailed;

function broadcastRecord(): UploadRecord
{
    return new UploadRecord(
        uploadId: 'u-1',
        fileName: 'doc.pdf',
        fileSize: 1024,
        mimeType: 'application/pdf',
        chunkSize: 256,
        totalChunks: 4,
        disk: 's3',
        finalPath: 'chunky/uploads/u-1/doc.pdf',
        userId: '7',
        status: UploadStatus::Completed,
    );
}

it('versions the payload and strips internal fields', function () {
    $payload = (new UploadCompleted(broadcastRecord()))->broadcastWith();

    expect($payload)
        ->toHaveKey('v', 1)
        ->toHaveKey('upload_id', 'u-1')
        ->toHaveKey('status', 'completed')
        ->not->toHaveKey('disk')
        ->not->toHaveKey('final_path')
        ->not->toHaveKey('user_id');
});

it('includes the reason on UploadFailed without leaking internals', function () {
    $payload = (new UploadFailed(broadcastRecord(), 'hook exploded'))->broadcastWith();

    expect($payload)
        ->toHaveKey('reason', 'hook exploded')
        ->not->toHaveKey('disk')
        ->not->toHaveKey('final_path');
});

it('does not broadcast when broadcasting is disabled', function () {
    config(['chunky.broadcasting.enabled' => false]);

    expect((new UploadCompleted(broadcastRecord()))->broadcastWhen())->toBeFalse();
});

it('broadcasts completion but excludes high-frequency events by default', function () {
    config(['chunky.broadcasting.enabled' => true]);
    config(['chunky.broadcasting.except' => [ChunkUploaded::class]]);

    expect((new UploadCompleted(broadcastRecord()))->broadcastWhen())->toBeTrue();
    expect((new ChunkUploaded('u-1', 0, 1, 4))->broadcastWhen())->toBeFalse();
});
