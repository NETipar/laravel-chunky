<?php

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

it('skips checksum verification when disabled', function () {
    config(['chunky.verify_integrity' => false]);

    $uploadId = initiateUpload($this);

    Event::fake([ChunkUploaded::class]);

    $chunk = UploadedFile::fake()->create('chunk', 1024);

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
        'checksum' => 'this-would-normally-fail',
    ]);

    $response->assertOk();
});
