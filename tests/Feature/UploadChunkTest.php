<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\UploadInitiated;

beforeEach(function () {
    Storage::fake('local');
});

function initiateUpload($test, int $fileSize = 2 * 1024 * 1024): string
{
    Event::fake([UploadInitiated::class]);

    $response = $test->postJson('/api/chunky/upload', [
        'file_name' => 'test-file.bin',
        'file_size' => $fileSize,
        'mime_type' => 'application/octet-stream',
    ]);

    Event::clearResolvedInstances();

    return $response->json('upload_id');
}

it('uploads a chunk successfully', function () {
    $uploadId = initiateUpload($this);

    Event::fake([ChunkUploaded::class]);

    $chunk = UploadedFile::fake()->create('chunk', 1024);
    $checksum = hash('sha256', $chunk->getContent());

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
        'checksum' => $checksum,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['chunk_index', 'is_complete', 'uploaded_count', 'total_chunks', 'progress'])
        ->assertJson([
            'chunk_index' => 0,
            'is_complete' => false,
            'uploaded_count' => 1,
        ]);

    Event::assertDispatched(ChunkUploaded::class);
});

it('validates required chunk fields', function () {
    $uploadId = initiateUpload($this);

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['chunk', 'chunk_index']);
});

it('rejects chunk with invalid checksum', function () {
    $uploadId = initiateUpload($this);

    $chunk = UploadedFile::fake()->create('chunk', 1024);

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
        'checksum' => 'invalid-checksum-value',
    ]);

    $response->assertStatus(500);
});

it('allows chunk without checksum when integrity verification is enabled', function () {
    $uploadId = initiateUpload($this);

    Event::fake([ChunkUploaded::class]);

    $chunk = UploadedFile::fake()->create('chunk', 1024);

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
    ]);

    $response->assertOk();
});

it('rejects a chunk_index above total_chunks', function () {
    $uploadId = initiateUpload($this, fileSize: 2 * 1024 * 1024);

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => UploadedFile::fake()->create('chunk', 1024),
        'chunk_index' => 9999,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['chunk_index']);
});

it('rejects late chunks against a cancelled upload with HTTP 409', function () {
    $uploadId = initiateUpload($this);

    // Cancel the upload first.
    $this->deleteJson("/api/chunky/upload/{$uploadId}")->assertStatus(204);

    // A late chunk POST must not be accepted.
    $chunk = UploadedFile::fake()->create('chunk', 1024);

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
    ]);

    $response->assertStatus(409);

    expect($response->json('message'))->toContain('no longer accepting chunks');
});

it('skips checksum verification when disabled', function () {
    config(['chunky.chunks.verify_integrity' => false]);

    $uploadId = initiateUpload($this);

    Event::fake([ChunkUploaded::class]);

    $chunk = UploadedFile::fake()->create('chunk', 1024);

    // The checksum format validator (sha256 = 64 hex chars) runs even
    // when verify_integrity is off, but a hex string still passes —
    // verify_integrity gates the *content* check, not the format check.
    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
        'checksum' => str_repeat('a', 64),
    ]);

    $response->assertOk();
});
