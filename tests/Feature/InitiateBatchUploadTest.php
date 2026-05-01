<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Enums\BatchStatus;
use NETipar\Chunky\Events\BatchInitiated;
use NETipar\Chunky\Events\UploadInitiated;
use NETipar\Chunky\Models\ChunkyBatch;

beforeEach(function () {
    Event::fake([BatchInitiated::class, UploadInitiated::class]);
});

function makeBatch(?string $context = null): string
{
    $manager = app(ChunkyManager::class);

    $batch = $manager->initiateBatch(2, context: $context);

    return $batch->batchId;
}

it('uses the batch context for validation, not the request context', function () {
    $manager = app(ChunkyManager::class);
    $manager->context('photos', rules: fn () => ['file_size' => ['max:1000']]);
    $manager->context('documents', rules: fn () => ['file_size' => ['max:100000']]);

    $batchId = makeBatch(context: 'photos');

    // The request claims context: documents (which would allow 50KB)
    // but the batch's context is photos (max 1KB). Validation must
    // come from the batch — otherwise we have a context bypass.
    $response = $this->postJson("/api/chunky/batch/{$batchId}/upload", [
        'file_name' => 'photo.jpg',
        'file_size' => 50000,
        'mime_type' => 'image/jpeg',
        'context' => 'documents',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['file_size']);
});

it('rejects new uploads on a Completed batch with a 422 validation error', function () {
    $batchId = makeBatch();

    ChunkyBatch::where('batch_id', $batchId)->update([
        'status' => BatchStatus::Completed,
    ]);

    $response = $this->postJson("/api/chunky/batch/{$batchId}/upload", [
        'file_name' => 'late.pdf',
        'file_size' => 1024,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['batch']);
});

it('rejects new uploads on a PartiallyCompleted batch', function () {
    $batchId = makeBatch();

    ChunkyBatch::where('batch_id', $batchId)->update([
        'status' => BatchStatus::PartiallyCompleted,
    ]);

    $response = $this->postJson("/api/chunky/batch/{$batchId}/upload", [
        'file_name' => 'late.pdf',
        'file_size' => 1024,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['batch']);
});

it('accepts uploads on a Pending batch normally', function () {
    $batchId = makeBatch();

    $response = $this->postJson("/api/chunky/batch/{$batchId}/upload", [
        'file_name' => 'first.pdf',
        'file_size' => 1024,
    ]);

    $response->assertStatus(201)->assertJsonStructure(['upload_id']);
});

it('caps the metadata array size to chunky.metadata.max_keys', function () {
    config(['chunky.metadata.max_keys' => 3]);

    $batchId = makeBatch();

    $response = $this->postJson("/api/chunky/batch/{$batchId}/upload", [
        'file_name' => 'huge.pdf',
        'file_size' => 1024,
        'metadata' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['metadata']);
});
