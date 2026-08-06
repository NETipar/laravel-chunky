<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\ChunkUploaded;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function initiateForChunk(object $test): string
{
    return (string) chunkyInitiate($test, 'f.bin', 20)->assertStatus(201)->json('upload_id');
}

it('accepts an intermediate chunk and reports uploading', function () {
    Event::fake([ChunkUploaded::class]);
    $uploadId = initiateForChunk($this);

    chunkyChunk($this, $uploadId, 0, '01234567')
        ->assertOk()
        ->assertJson([
            'status' => 'uploading',
            'chunk_index' => 0,
            'uploaded_count' => 1,
            'total_chunks' => 3,
        ]);

    Event::assertDispatched(ChunkUploaded::class);
});

it('validates required chunk fields', function () {
    $uploadId = initiateForChunk($this);

    $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['chunk', 'chunk_index']);
});

it('rejects a chunk index beyond the total with 422', function () {
    $uploadId = initiateForChunk($this);

    chunkyChunk($this, $uploadId, 99, 'xx')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['chunk_index']);
});

it('rejects a chunk with a mismatched checksum (422 checksum_mismatch)', function () {
    $uploadId = initiateForChunk($this);

    chunkyChunk($this, $uploadId, 0, '01234567', ['checksum' => str_repeat('a', 64)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'checksum_mismatch');
});

it('accepts a chunk with a matching checksum', function () {
    $uploadId = initiateForChunk($this);
    $bytes = '01234567';

    chunkyChunk($this, $uploadId, 0, $bytes, ['checksum' => hash('sha256', $bytes)])->assertOk();
});

it('allows a chunk without a checksum by default', function () {
    $uploadId = initiateForChunk($this);

    chunkyChunk($this, $uploadId, 0, '01234567')->assertOk();
});

it('rejects a late chunk against a cancelled upload with 409', function () {
    $uploadId = initiateForChunk($this);
    $this->deleteJson("/api/chunky/upload/{$uploadId}")->assertOk();

    chunkyChunk($this, $uploadId, 0, '01234567')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_state');
});

it('returns 404 for a chunk against an unknown upload', function () {
    $this->post('/api/chunky/upload/missing/chunks', [
        'chunk' => UploadedFile::fake()->createWithContent('chunk', 'x'),
        'chunk_index' => 0,
    ])->assertStatus(404)->assertJsonPath('error.code', 'upload_not_found');
});
