<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\UploadInitiated;

beforeEach(function () {
    Storage::fake('local');
});

it('returns upload status', function () {
    Event::fake([UploadInitiated::class]);

    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'report.pdf',
        'file_size' => 3 * 1024 * 1024,
        'mime_type' => 'application/pdf',
    ]);

    $uploadId = $response->json('upload_id');

    $statusResponse = $this->getJson("/api/chunky/upload/{$uploadId}");

    $statusResponse->assertOk()
        ->assertJsonStructure([
            'upload_id', 'file_name', 'file_size', 'mime_type',
            'chunk_size', 'total_chunks', 'status', 'uploaded_chunks',
        ])
        ->assertJson([
            'upload_id' => $uploadId,
            'file_name' => 'report.pdf',
            'file_size' => 3 * 1024 * 1024,
            'status' => 'pending',
            'uploaded_chunks' => [],
        ]);

    // Internal fields must never leak through the public status endpoint.
    $statusResponse->assertJsonMissing(['disk' => true]);
    expect($statusResponse->json())
        ->not->toHaveKey('disk')
        ->not->toHaveKey('final_path')
        ->not->toHaveKey('user_id');
});

it('returns 404 for non-existent upload', function () {
    $response = $this->getJson('/api/chunky/upload/non-existent-id');

    $response->assertStatus(404)
        ->assertJson(['message' => 'Upload not found.']);
});
