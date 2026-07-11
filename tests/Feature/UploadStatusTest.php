<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('returns a sanitized pending status', function () {
    $uploadId = (string) chunkyInitiate($this, 'report.pdf', 20, ['mime_type' => 'application/pdf'])
        ->assertStatus(201)->json('upload_id');

    $response = $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJsonStructure(['upload_id', 'status', 'progress', 'uploaded_chunks', 'total_chunks', 'file_name', 'file_size'])
        ->assertJson([
            'upload_id' => $uploadId,
            'status' => 'pending',
            'file_name' => 'report.pdf',
            'uploaded_chunks' => [],
        ]);

    expect($response->json())
        ->not->toHaveKey('disk')
        ->not->toHaveKey('final_path')
        ->not->toHaveKey('user_id');
});

it('returns 404 for an unknown upload', function () {
    $this->getJson('/api/chunky/upload/missing')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'upload_not_found');
});
