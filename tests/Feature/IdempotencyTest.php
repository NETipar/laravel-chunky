<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\ChunkUploaded;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
});

it('replays the cached response for a repeated Idempotency-Key', function () {
    $uploadId = (string) chunkyInitiate($this, 'f.bin', 20)->assertStatus(201)->json('upload_id');
    Event::fake([ChunkUploaded::class]);

    $headers = ['Idempotency-Key' => 'key-1'];
    $first = chunkyChunk($this, $uploadId, 0, '01234567', [], $headers)->assertOk();
    $second = chunkyChunk($this, $uploadId, 0, '01234567', [], $headers)->assertOk();

    expect($second->json())->toEqual($first->json());
    Event::assertDispatchedTimes(ChunkUploaded::class, 1);
});

it('replays based on the checksum when no key is supplied', function () {
    $uploadId = (string) chunkyInitiate($this, 'f.bin', 20)->assertStatus(201)->json('upload_id');
    Event::fake([ChunkUploaded::class]);

    $checksum = hash('sha256', '01234567');
    chunkyChunk($this, $uploadId, 0, '01234567', ['checksum' => $checksum])->assertOk();
    chunkyChunk($this, $uploadId, 0, '01234567', ['checksum' => $checksum])->assertOk();

    Event::assertDispatchedTimes(ChunkUploaded::class, 1);
});
