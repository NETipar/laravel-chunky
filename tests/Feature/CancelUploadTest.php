<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\UploadCancelled;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('cancels an in-progress upload and purges its chunks', function () {
    Event::fake([UploadCancelled::class]);
    $uploadId = (string) chunkyInitiate($this, 'f.bin', 20)->assertStatus(201)->json('upload_id');
    chunkyChunk($this, $uploadId, 0, '01234567')->assertOk();

    Storage::disk('local')->assertExists("chunky/chunks/{$uploadId}/chunk_0");

    $this->deleteJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertExactJson(['status' => 'cancelled']);

    Storage::disk('local')->assertMissing("chunky/chunks/{$uploadId}");
    $this->getJson("/api/chunky/upload/{$uploadId}")->assertJson(['status' => 'cancelled']);
    Event::assertDispatched(UploadCancelled::class);
});

it('is idempotent when already cancelled', function () {
    $uploadId = (string) chunkyInitiate($this, 'f.bin', 20)->assertStatus(201)->json('upload_id');

    $this->deleteJson("/api/chunky/upload/{$uploadId}")->assertOk();
    $this->deleteJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertExactJson(['status' => 'cancelled']);
});

it('returns 404 cancelling an unknown upload', function () {
    $this->deleteJson('/api/chunky/upload/missing')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'upload_not_found');
});

it('returns 409 cancelling a completed upload', function () {
    ['upload_id' => $uploadId] = chunkyCompleteUpload($this, 'HELLO-CHUNKY-WORLD-!');

    $this->deleteJson("/api/chunky/upload/{$uploadId}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_state');
});
