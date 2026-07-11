<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Adapters\Storage\FlysystemChunkStore;
use NETipar\Chunky\Exceptions\ChunkyException;

beforeEach(fn () => Storage::fake('local'));

function chunkStore(): FlysystemChunkStore
{
    return new FlysystemChunkStore(Storage::disk('local'), 'chunky/chunks');
}

it('stores a chunk and reports its existence', function () {
    $store = chunkStore();

    expect($store->exists('up-1', 0))->toBeFalse();

    $store->put('up-1', 0, UploadedFile::fake()->createWithContent('chunk', 'hello'));

    expect($store->exists('up-1', 0))->toBeTrue();
    Storage::disk('local')->assertExists('chunky/chunks/up-1/chunk_0');
});

it('reads a stored chunk as a stream', function () {
    $store = chunkStore();
    $store->put('up-1', 2, UploadedFile::fake()->createWithContent('chunk', 'world'));

    $stream = $store->readStream('up-1', 2);

    expect(stream_get_contents($stream))->toBe('world');
    fclose($stream);
});

it('purges all chunks for an upload', function () {
    $store = chunkStore();
    $store->put('up-1', 0, UploadedFile::fake()->createWithContent('chunk', 'a'));
    $store->put('up-1', 1, UploadedFile::fake()->createWithContent('chunk', 'b'));

    $store->purge('up-1');

    Storage::disk('local')->assertMissing('chunky/chunks/up-1');
});

it('throws reading a missing chunk', function () {
    expect(fn () => chunkStore()->readStream('missing', 0))
        ->toThrow(ChunkyException::class);
});

it('rejects a path-traversal upload id', function () {
    expect(fn () => chunkStore()->exists('../../etc/passwd', 0))
        ->toThrow(ChunkyException::class);
});
