<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\BatchCompleted;
use NETipar\Chunky\Models\ChunkyBatch;
use NETipar\Chunky\Profiles\ProfileRegistry;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function createBatch(object $test, int $totalFiles = 2, array $extra = []): string
{
    return (string) $test->postJson('/api/chunky/batch', array_merge(['total_files' => $totalFiles], $extra))
        ->assertStatus(201)
        ->json('batch_id');
}

it('initiates a batch', function () {
    $this->postJson('/api/chunky/batch', ['total_files' => 3])
        ->assertStatus(201)
        ->assertJson(['total_files' => 3])
        ->assertJsonStructure(['batch_id', 'total_files']);
});

it('initiates a batch member and reports batch status', function () {
    $batchId = createBatch($this, 2);

    $this->postJson("/api/chunky/batch/{$batchId}/upload", ['file_name' => 'a.bin', 'file_size' => 20])
        ->assertStatus(201)
        ->assertJsonStructure(['upload_id', 'batch_id', 'chunk_size', 'total_chunks']);

    $this->getJson("/api/chunky/batch/{$batchId}")
        ->assertOk()
        ->assertJson([
            'batch_id' => $batchId,
            'total_files' => 2,
            'completed_count' => 0,
            'failed_count' => 0,
        ])
        ->assertJsonStructure(['status', 'uploads']);
});

it('rejects a member on a terminal batch with 409', function () {
    $batchId = createBatch($this, 1);
    ChunkyBatch::query()->where('batch_id', $batchId)->update(['status' => 'completed']);

    $this->postJson("/api/chunky/batch/{$batchId}/upload", ['file_name' => 'late.bin', 'file_size' => 20])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_state');
});

it('validates a member against the batch profile, not the request profile', function () {
    app(ProfileRegistry::class)->simple('photos', 'photos', ['max_size' => 10]);
    app(ProfileRegistry::class)->simple('documents', 'documents', ['max_size' => 100000]);

    $batchId = createBatch($this, 1, ['profile' => 'photos']);

    // Request claims the permissive 'documents' profile, but the batch's
    // 'photos' profile (max 10 bytes) must win.
    $this->postJson("/api/chunky/batch/{$batchId}/upload", [
        'file_name' => 'photo.jpg',
        'file_size' => 5000,
        'profile' => 'documents',
    ])->assertStatus(422)->assertJsonValidationErrors(['file_size']);
});

it('completes the batch when its only member completes', function () {
    Event::fake([BatchCompleted::class]);
    $batchId = createBatch($this, 1);

    $member = $this->postJson("/api/chunky/batch/{$batchId}/upload", ['file_name' => 'a.bin', 'file_size' => 20])
        ->assertStatus(201);
    $uploadId = (string) $member->json('upload_id');
    $chunkSize = (int) $member->json('chunk_size');
    $total = (int) $member->json('total_chunks');

    $content = 'HELLO-CHUNKY-WORLD-!';
    for ($i = 0; $i < $total; $i++) {
        chunkyChunk($this, $uploadId, $i, substr($content, $i * $chunkSize, $chunkSize));
    }

    $this->getJson("/api/chunky/batch/{$batchId}")->assertOk()->assertJson(['status' => 'completed']);
    Event::assertDispatched(BatchCompleted::class);
});

it('cancels a batch and its non-terminal members', function () {
    $batchId = createBatch($this, 2);
    $member = $this->postJson("/api/chunky/batch/{$batchId}/upload", ['file_name' => 'a.bin', 'file_size' => 20])
        ->assertStatus(201);
    $uploadId = (string) $member->json('upload_id');

    $this->deleteJson("/api/chunky/batch/{$batchId}")
        ->assertOk()
        ->assertExactJson(['status' => 'cancelled']);

    $this->getJson("/api/chunky/batch/{$batchId}")->assertOk()->assertJson(['status' => 'cancelled']);
    $this->getJson("/api/chunky/upload/{$uploadId}")->assertOk()->assertJson(['status' => 'cancelled']);
});
