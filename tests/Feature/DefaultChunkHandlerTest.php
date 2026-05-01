<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Handlers\DefaultChunkHandler;

beforeEach(function () {
    Storage::fake('local');
});

it('stores a chunk to the configured disk', function () {
    $handler = new DefaultChunkHandler;
    $chunk = UploadedFile::fake()->createWithContent('chunk_0', 'chunk-data-here');

    $handler->store('upload-123', 0, $chunk);

    Storage::disk('local')->assertExists('chunky/temp/upload-123/chunk_0');
});

it('stores multiple chunks', function () {
    $handler = new DefaultChunkHandler;

    for ($i = 0; $i < 3; $i++) {
        $chunk = UploadedFile::fake()->createWithContent("chunk_{$i}", "data-{$i}");
        $handler->store('upload-123', $i, $chunk);
    }

    Storage::disk('local')->assertExists('chunky/temp/upload-123/chunk_0');
    Storage::disk('local')->assertExists('chunky/temp/upload-123/chunk_1');
    Storage::disk('local')->assertExists('chunky/temp/upload-123/chunk_2');
});

it('assembles chunks into a single file', function () {
    $handler = new DefaultChunkHandler;

    for ($i = 0; $i < 3; $i++) {
        $chunk = UploadedFile::fake()->createWithContent("chunk_{$i}", "part{$i}");
        $handler->store('upload-456', $i, $chunk);
    }

    $finalPath = $handler->assemble('upload-456', 'result.bin', 3);

    expect($finalPath)->toBe('chunky/uploads/upload-456/result.bin');

    $content = file_get_contents(Storage::disk('local')->path($finalPath));

    expect($content)->toBe('part0part1part2');
});

it('cleans up temp directory after assembly', function () {
    $handler = new DefaultChunkHandler;

    $chunk = UploadedFile::fake()->createWithContent('chunk_0', 'data');
    $handler->store('upload-789', 0, $chunk);

    Storage::disk('local')->assertExists('chunky/temp/upload-789/chunk_0');

    $handler->cleanup('upload-789');

    Storage::disk('local')->assertMissing('chunky/temp/upload-789');
});

it('assembles chunks via filesystem streams without relying on disk()->path()', function () {
    $handler = new DefaultChunkHandler;

    for ($i = 0; $i < 5; $i++) {
        $handler->store('stream-1', $i, UploadedFile::fake()->createWithContent("chunk_{$i}", "block-{$i}-"));
    }

    $finalPath = $handler->assemble('stream-1', 'big.bin', 5);

    expect(Storage::disk('local')->get($finalPath))->toBe('block-0-block-1-block-2-block-3-block-4-');
});

it('throws when a chunk is missing during assembly', function () {
    $handler = new DefaultChunkHandler;

    $handler->store('missing-1', 0, UploadedFile::fake()->createWithContent('chunk_0', 'a'));

    expect(fn () => $handler->assemble('missing-1', 'file.bin', 2))
        ->toThrow(RuntimeException::class);
});
