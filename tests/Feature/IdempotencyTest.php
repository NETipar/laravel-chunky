<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\UploadInitiated;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
});

function initiateForIdempotency($test): string
{
    Event::fake([UploadInitiated::class]);

    $response = $test->postJson('/api/chunky/upload', [
        'file_name' => 'idem.bin',
        'file_size' => 2 * 1024 * 1024,
    ]);

    Event::clearResolvedInstances();

    return $response->json('upload_id');
}

it('replays the cached response when the same Idempotency-Key is retried', function () {
    $uploadId = initiateForIdempotency($this);

    Event::fake([ChunkUploaded::class]);

    $chunk = UploadedFile::fake()->create('chunk', 1024);
    $headers = ['Idempotency-Key' => 'client-key-1'];

    $first = $this->postJson(
        "/api/chunky/upload/{$uploadId}/chunks",
        ['chunk' => $chunk, 'chunk_index' => 0],
        $headers,
    );
    $first->assertOk();

    // Same client-supplied key + same upload + same chunk index → replay.
    $second = $this->postJson(
        "/api/chunky/upload/{$uploadId}/chunks",
        ['chunk' => $chunk, 'chunk_index' => 0],
        $headers,
    );

    $second->assertOk();
    expect($second->json())->toEqual($first->json());

    // The replay must NOT fire ChunkUploaded a second time.
    Event::assertDispatchedTimes(ChunkUploaded::class, 1);
});

it('replays based on the chunk checksum when no Idempotency-Key is sent', function () {
    $uploadId = initiateForIdempotency($this);

    Event::fake([ChunkUploaded::class]);

    $chunk = UploadedFile::fake()->create('chunk', 1024);
    $checksum = hash('sha256', $chunk->getContent());

    $first = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
        'checksum' => $checksum,
    ]);
    $first->assertOk();

    $second = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
        'checksum' => $checksum,
    ]);

    $second->assertOk();
    Event::assertDispatchedTimes(ChunkUploaded::class, 1);
});

it('does not replay when chunky.idempotency.enabled is false', function () {
    config(['chunky.idempotency.enabled' => false]);

    $uploadId = initiateForIdempotency($this);

    Event::fake([ChunkUploaded::class]);

    $chunk = UploadedFile::fake()->create('chunk', 1024);

    $this->postJson(
        "/api/chunky/upload/{$uploadId}/chunks",
        ['chunk' => $chunk, 'chunk_index' => 0],
        ['Idempotency-Key' => 'k'],
    )->assertOk();

    $this->postJson(
        "/api/chunky/upload/{$uploadId}/chunks",
        ['chunk' => $chunk, 'chunk_index' => 0],
        ['Idempotency-Key' => 'k'],
    )->assertOk();

    // Both requests went through to the manager → 2 events.
    Event::assertDispatchedTimes(ChunkUploaded::class, 2);
});
