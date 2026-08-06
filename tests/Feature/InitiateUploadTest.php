<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\UploadInitiated;
use NETipar\Chunky\Jobs\AssembleFileJob;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Ports\UploadRepository;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('initiates an upload', function () {
    Event::fake([UploadInitiated::class]);

    chunkyInitiate($this, 'video.mp4', 20, ['mime_type' => 'video/mp4'])
        ->assertStatus(201)
        ->assertJsonStructure(['upload_id', 'chunk_size', 'total_chunks', 'resumed', 'uploaded_chunks'])
        ->assertJson(['chunk_size' => 8, 'total_chunks' => 3, 'resumed' => false, 'uploaded_chunks' => []]);

    Event::assertDispatched(UploadInitiated::class);
});

it('validates required fields with the error envelope', function () {
    $this->postJson('/api/chunky/upload', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file_name', 'file_size'])
        ->assertJsonPath('error.code', 'validation_failed');
});

it('requires a positive file size', function () {
    chunkyInitiate($this, 'f.bin', 0)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file_size']);
});

it('rejects a file over the max size with 422 (not 413)', function () {
    config(['chunky.limits.max_file_size' => 10]);

    chunkyInitiate($this, 'f.bin', 20)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file_size'])
        ->assertJsonPath('error.code', 'validation_failed');
});

it('caps the metadata key count', function () {
    config(['chunky.limits.metadata_max_keys' => 3]);

    chunkyInitiate($this, 'f.bin', 20, ['metadata' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata']);
});

it('rejects path-traversal file names', function () {
    foreach (['../../etc/passwd', 'evil/../bad.txt', '..', '.', "with\0null.txt", 'C:\\Windows\\evil'] as $name) {
        chunkyInitiate($this, $name, 20)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file_name']);
    }
});

it('accepts unicode and punctuation in file names', function () {
    foreach (['árvíztűrő.pdf', 'name with spaces.txt', 'a (1).png', 'résumé.docx'] as $name) {
        chunkyInitiate($this, $name, 20)->assertStatus(201);
    }
});

it('resumes an existing upload by fingerprint', function () {
    $first = chunkyInitiate($this, 'f.bin', 20, ['fingerprint' => 'fp-1'])->assertStatus(201);
    $uploadId = (string) $first->json('upload_id');
    chunkyChunk($this, $uploadId, 0, '01234567')->assertOk();

    chunkyInitiate($this, 'f.bin', 20, ['fingerprint' => 'fp-1'])
        ->assertStatus(200)
        ->assertJson([
            'upload_id' => $uploadId,
            'resumed' => true,
            'uploaded_chunks' => [0],
        ]);
});

it('rejects an unknown profile', function () {
    chunkyInitiate($this, 'f.bin', 20, ['profile' => 'does-not-exist'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'profile_not_found');
});

/**
 * Simulates a finishing chunk whose response was lost mid-flight: the bytes and
 * the tracker mark exist, but assembly never started.
 */
function stallUploadAtFullCompletion(object $test, string $fingerprint): string
{
    $first = chunkyInitiate($test, 'f.bin', 20, ['fingerprint' => $fingerprint])->assertStatus(201);
    $uploadId = (string) $first->json('upload_id');

    chunkyChunk($test, $uploadId, 0, '01234567')->assertOk();
    chunkyChunk($test, $uploadId, 1, '89abcdef')->assertOk();

    app(ChunkStore::class)->put($uploadId, 2, UploadedFile::fake()->createWithContent('chunk', 'ghij'));
    app(UploadRepository::class)->markChunk($uploadId, 2);

    return $uploadId;
}

it('recovers a stalled, fully uploaded resume by running sync assembly', function () {
    $uploadId = stallUploadAtFullCompletion($this, 'fp-stalled-sync');

    chunkyInitiate($this, 'f.bin', 20, ['fingerprint' => 'fp-stalled-sync'])
        ->assertStatus(200)
        ->assertJson(['upload_id' => $uploadId, 'resumed' => true, 'uploaded_chunks' => [0, 1, 2]]);

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJsonPath('status', 'completed');
});

it('dispatches the assembly job for a stalled resume in queue mode', function () {
    config(['chunky.assembly.mode' => 'queue']);
    Queue::fake();

    $uploadId = stallUploadAtFullCompletion($this, 'fp-stalled-queue');

    chunkyInitiate($this, 'f.bin', 20, ['fingerprint' => 'fp-stalled-queue'])
        ->assertStatus(200)
        ->assertJson(['resumed' => true]);

    Queue::assertPushed(AssembleFileJob::class, fn (AssembleFileJob $job): bool => $job->uploadId === $uploadId);
});

it('does not start assembly on a stalled resume when the required file checksum is missing', function () {
    config(['chunky.integrity.require_full_file' => true]);

    $uploadId = stallUploadAtFullCompletion($this, 'fp-stalled-checksum');

    chunkyInitiate($this, 'f.bin', 20, ['fingerprint' => 'fp-stalled-checksum'])
        ->assertStatus(200)
        ->assertJson(['resumed' => true]);

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJsonPath('status', 'uploading');
});
