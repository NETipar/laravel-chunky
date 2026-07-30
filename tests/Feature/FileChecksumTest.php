<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use NETipar\Chunky\Models\ChunkedUpload;
use NETipar\Chunky\Services\AssemblyRunner;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

const CHECKSUM_CONTENT = 'HELLO-CHUNKY-WORLD-!'; // 20 bytes -> 3 chunks of size 8

/**
 * Drives a full upload, attaching `file_checksum` to the final chunk.
 *
 * @return array{upload_id: string, response: TestResponse}
 */
function uploadWithFileChecksum(object $test, string $content, ?string $fileChecksum): array
{
    $initiate = chunkyInitiate($test, 'file.bin', strlen($content))->assertStatus(201);
    $uploadId = (string) $initiate->json('upload_id');
    $chunkSize = (int) $initiate->json('chunk_size');
    $totalChunks = (int) $initiate->json('total_chunks');

    $response = $initiate;
    for ($i = 0; $i < $totalChunks; $i++) {
        $bytes = substr($content, $i * $chunkSize, $chunkSize);
        $extra = ($i === $totalChunks - 1 && $fileChecksum !== null) ? ['file_checksum' => $fileChecksum] : [];
        $response = chunkyChunk($test, $uploadId, $i, $bytes, $extra);
    }

    return ['upload_id' => $uploadId, 'response' => $response];
}

it('completes when the whole-file checksum matches', function () {
    $checksum = hash('sha256', CHECKSUM_CONTENT);

    ['upload_id' => $uploadId, 'response' => $response] = uploadWithFileChecksum($this, CHECKSUM_CONTENT, $checksum);

    $response->assertOk()->assertJson(['status' => 'completed']);
    expect(ChunkedUpload::query()->find($uploadId)?->file_checksum)->toBe($checksum);
});

it('rejects a sync assembly with 422 checksum_mismatch and keeps the chunks for diagnosis', function () {
    ['upload_id' => $uploadId, 'response' => $response] = uploadWithFileChecksum($this, CHECKSUM_CONTENT, str_repeat('0', 64));

    $response->assertStatus(422)->assertJsonPath('error.code', 'checksum_mismatch');

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJson(['status' => 'failed']);

    Storage::disk('local')->assertExists("chunky/chunks/{$uploadId}");
});

it('marks a queued assembly failed on checksum mismatch', function () {
    config(['chunky.assembly.mode' => 'queue']);
    Queue::fake();

    ['upload_id' => $uploadId, 'response' => $response] = uploadWithFileChecksum($this, CHECKSUM_CONTENT, str_repeat('0', 64));

    $response->assertOk()->assertJson(['status' => 'assembling']);

    expect(fn () => app(AssemblyRunner::class)->run($uploadId))
        ->toThrow(ChunkIntegrityException::class);

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJson(['status' => 'failed']);
});

it('persists the first non-empty checksum and ignores later values', function () {
    $checksum = hash('sha256', CHECKSUM_CONTENT);

    $initiate = chunkyInitiate($this, 'file.bin', strlen(CHECKSUM_CONTENT))->assertStatus(201);
    $uploadId = (string) $initiate->json('upload_id');

    chunkyChunk($this, $uploadId, 0, substr(CHECKSUM_CONTENT, 0, 8), ['file_checksum' => $checksum])->assertOk();
    chunkyChunk($this, $uploadId, 1, substr(CHECKSUM_CONTENT, 8, 8), ['file_checksum' => str_repeat('f', 64)])->assertOk();
    $final = chunkyChunk($this, $uploadId, 2, substr(CHECKSUM_CONTENT, 16, 8));

    $final->assertOk()->assertJson(['status' => 'completed']);
    expect(ChunkedUpload::query()->find($uploadId)?->file_checksum)->toBe($checksum);
});

it('rejects a malformed file_checksum with validation_failed', function () {
    $initiate = chunkyInitiate($this, 'file.bin', strlen(CHECKSUM_CONTENT))->assertStatus(201);
    $uploadId = (string) $initiate->json('upload_id');

    chunkyChunk($this, $uploadId, 0, substr(CHECKSUM_CONTENT, 0, 8), ['file_checksum' => 'not-a-hash'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('requires the checksum on completion when require_full_file is enabled, and recovers on retry', function () {
    config(['chunky.integrity.require_full_file' => true]);

    ['upload_id' => $uploadId, 'response' => $response] = uploadWithFileChecksum($this, CHECKSUM_CONTENT, null);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // The upload is not terminal — re-sending the final chunk with the
    // checksum completes it.
    $retry = chunkyChunk($this, $uploadId, 2, substr(CHECKSUM_CONTENT, 16, 8), [
        'file_checksum' => hash('sha256', CHECKSUM_CONTENT),
    ]);

    $retry->assertOk()->assertJson(['status' => 'completed']);
});

it('completes without a checksum when require_full_file is disabled', function () {
    ['response' => $response] = uploadWithFileChecksum($this, CHECKSUM_CONTENT, null);

    $response->assertOk()->assertJson(['status' => 'completed']);
});

it('verifies against both trackers', function (string $tracker) {
    config(['chunky.tracker' => $tracker]);

    ['response' => $ok] = uploadWithFileChecksum($this, CHECKSUM_CONTENT, hash('sha256', CHECKSUM_CONTENT));
    $ok->assertOk()->assertJson(['status' => 'completed']);

    ['response' => $bad] = uploadWithFileChecksum($this, CHECKSUM_CONTENT, str_repeat('0', 64));
    $bad->assertStatus(422)->assertJsonPath('error.code', 'checksum_mismatch');
})->with(['database', 'filesystem']);
