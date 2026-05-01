<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Handlers\DefaultChunkHandler;

beforeEach(function () {
    Storage::fake('local');
});

it('DefaultChunkHandler::assemble strips leading directories from a hostile file name (basename)', function () {
    $handler = new DefaultChunkHandler;

    // basename('../../etc/passwd') === 'passwd', so the assemble call no
    // longer escapes — it tries to assemble a `passwd` file under the
    // upload's own directory. That fails on the missing-chunks path here
    // (we didn't seed any), proving it took the *safe* branch.
    expect(fn () => $handler->assemble('upload-x', '../../etc/passwd', 1))
        ->toThrow(RuntimeException::class, 'could not be read');
});

it('DefaultChunkHandler::assemble refuses dot-only file names that basename leaves empty', function () {
    $handler = new DefaultChunkHandler;

    expect(fn () => $handler->assemble('upload-y', '.', 1))
        ->toThrow(RuntimeException::class, 'invalid file name');

    expect(fn () => $handler->assemble('upload-z', '..', 1))
        ->toThrow(RuntimeException::class, 'invalid file name');
});

it('simple() context save callback strips leading directories from a hostile file name', function () {
    Storage::fake('local');
    Storage::disk('local')->put('chunky/uploads/pt-1/safe.bin', 'hello');

    $manager = app(ChunkyManager::class);
    $manager->simple('docs', '/uploads');

    $callback = $manager->getContextSaveCallback('docs');

    $hostile = new UploadMetadata(
        uploadId: 'pt-1',
        fileName: '../../etc/passwd',
        fileSize: 1,
        mimeType: null,
        chunkSize: 1024,
        totalChunks: 1,
        disk: 'local',
        context: 'docs',
        finalPath: 'chunky/uploads/pt-1/safe.bin',
        status: UploadStatus::Completed,
    );

    // basename('../../etc/passwd') === 'passwd' → the file is moved to
    // /uploads/passwd, NOT /etc/passwd. Confirms the basename guard.
    $callback($hostile);

    expect(Storage::disk('local')->exists('uploads/passwd'))->toBeTrue();
    expect(Storage::disk('local')->exists('chunky/uploads/pt-1/safe.bin'))->toBeFalse();
});

it('simple() context save callback rejects dot-only file names', function () {
    Storage::fake('local');
    Storage::disk('local')->put('chunky/uploads/pt-2/source.bin', 'x');

    $manager = app(ChunkyManager::class);
    $manager->simple('docs2', '/uploads');

    $callback = $manager->getContextSaveCallback('docs2');

    $hostile = new UploadMetadata(
        uploadId: 'pt-2',
        fileName: '..',
        fileSize: 1,
        mimeType: null,
        chunkSize: 1024,
        totalChunks: 1,
        disk: 'local',
        context: 'docs2',
        finalPath: 'chunky/uploads/pt-2/source.bin',
        status: UploadStatus::Completed,
    );

    expect(fn () => $callback($hostile))
        ->toThrow(ChunkyException::class, 'invalid file name');
});
