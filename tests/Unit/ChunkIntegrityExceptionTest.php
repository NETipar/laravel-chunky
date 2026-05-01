<?php

declare(strict_types=1);

use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;

it('creates checksum mismatch exception with correct message', function () {
    $exception = ChunkIntegrityException::checksumMismatch('upload-123', 5);

    expect($exception)->toBeInstanceOf(ChunkIntegrityException::class);
    expect($exception)->toBeInstanceOf(ChunkyException::class);
    expect($exception->getMessage())->toContain('upload-123');
    expect($exception->getMessage())->toContain('5');
});

it('creates upload expired exception with correct message', function () {
    $exception = UploadExpiredException::forUpload('upload-456');

    expect($exception)->toBeInstanceOf(UploadExpiredException::class);
    expect($exception)->toBeInstanceOf(ChunkyException::class);
    expect($exception->getMessage())->toContain('upload-456');
});
