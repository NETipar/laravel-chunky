<?php

use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\UploadFailed;

function makeMetadata(): UploadMetadata
{
    return new UploadMetadata(
        uploadId: 'u-1',
        fileName: 'doc.pdf',
        fileSize: 1024,
        mimeType: 'application/pdf',
        chunkSize: 1024,
        totalChunks: 1,
        disk: 's3',
        context: 'documents',
        finalPath: 'chunky/uploads/u-1/doc.pdf',
        status: UploadStatus::Completed,
        userId: 7,
    );
}

it('UploadCompleted broadcastWith strips disk and finalPath by default', function () {
    config(['chunky.broadcasting.expose_internal_paths' => false]);

    $event = new UploadCompleted(makeMetadata());
    $payload = $event->broadcastWith();

    expect($payload)
        ->toHaveKey('uploadId', 'u-1')
        ->toHaveKey('fileName', 'doc.pdf')
        ->toHaveKey('fileSize', 1024)
        ->toHaveKey('context', 'documents')
        ->not->toHaveKey('finalPath')
        ->not->toHaveKey('disk');
});

it('UploadCompleted broadcastWith includes disk and finalPath when opted in', function () {
    config(['chunky.broadcasting.expose_internal_paths' => true]);

    $event = new UploadCompleted(makeMetadata());
    $payload = $event->broadcastWith();

    expect($payload)
        ->toHaveKey('finalPath', 'chunky/uploads/u-1/doc.pdf')
        ->toHaveKey('disk', 's3');
});

it('UploadFailed broadcastWith strips disk by default', function () {
    config(['chunky.broadcasting.expose_internal_paths' => false]);

    $event = new UploadFailed(makeMetadata()->withStatus(UploadStatus::Failed), 'save callback failed');
    $payload = $event->broadcastWith();

    expect($payload)
        ->toHaveKey('uploadId', 'u-1')
        ->toHaveKey('reason', 'save callback failed')
        ->not->toHaveKey('disk')
        ->not->toHaveKey('finalPath');
});

it('UploadFailed broadcastWith includes disk when opted in', function () {
    config(['chunky.broadcasting.expose_internal_paths' => true]);

    $event = new UploadFailed(makeMetadata()->withStatus(UploadStatus::Failed), 'oops');
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('disk', 's3');
});
