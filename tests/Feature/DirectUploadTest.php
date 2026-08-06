<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use NETipar\Chunky\Events\FileAssembled;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\UploadFailed;
use NETipar\Chunky\Exceptions\ChunkIndexOutOfRangeException;
use NETipar\Chunky\Models\ChunkedUpload;
use NETipar\Chunky\Ports\DirectUploadTransport;
use NETipar\Chunky\Profiles\CompletedUpload;
use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Profiles\UploadContext;
use NETipar\Chunky\Profiles\UploadProfile;
use NETipar\Chunky\Services\DirectUploadService;
use NETipar\Chunky\Tests\Doubles\InMemoryDirectTransport;

uses(RefreshDatabase::class);

const DIRECT_PART = 5 * 1024 * 1024;

beforeEach(function () {
    Storage::fake('local');
    config()->set('chunky.chunks.size', DIRECT_PART);
    config()->set('chunky.transports.direct_s3.disk', 's3');
    config()->set('filesystems.disks.s3', ['driver' => 's3', 'bucket' => 'test-bucket', 'region' => 'eu-central-1']);

    $this->transport = new InMemoryDirectTransport;
    $this->app->instance(DirectUploadTransport::class, $this->transport);

    app(ProfileRegistry::class)->register('direct', new class extends UploadProfile
    {
        public function directory(UploadContext $context): string
        {
            return 'direct-files';
        }

        public function transport(): string
        {
            return 'direct_s3';
        }

        public function completed(CompletedUpload $upload): ?array
        {
            return ['stored' => $upload->path];
        }
    });
});

function directInitiate(object $test, int $fileSize, array $extra = []): TestResponse
{
    return chunkyInitiate($test, 'video.mp4', $fileSize, array_merge(['profile' => 'direct'], $extra));
}

function directParts(int $totalChunks): array
{
    return array_map(
        static fn (int $index): array => ['index' => $index, 'etag' => '"etag-'.$index.'"'],
        range(0, $totalChunks - 1),
    );
}

it('returns the transport bootstrap with presigned part URLs on initiate', function () {
    $response = directInitiate($this, 3 * DIRECT_PART);

    $response->assertStatus(201)
        ->assertJsonPath('transport.mode', 'direct_s3')
        ->assertJsonStructure(['transport' => ['mode', 'part_urls', 'expires_at']]);

    // The wire shape is a JSON object keyed by index, not an array.
    expect((string) $response->getContent())->toContain('"part_urls":{"0":');

    $urls = $response->json('transport.part_urls');
    expect(array_keys($urls))->toBe([0, 1, 2]);
    expect($urls[1])->toContain('partNumber=2');

    $uploadId = (string) $response->json('upload_id');
    $model = ChunkedUpload::query()->find($uploadId);
    expect($model?->transport)->toBe('direct_s3');
    expect($model?->remote_upload_id)->toBe('remote-1');
});

it('caps the initiate URL batch at 100', function () {
    $response = directInitiate($this, 150 * DIRECT_PART);

    $response->assertStatus(201);
    expect($response->json('total_chunks'))->toBe(150);
    expect(count($response->json('transport.part_urls')))->toBe(100);
});

it('rejects a part size below the S3 minimum', function () {
    config()->set('chunky.chunks.size', 1024);

    directInitiate($this, 4096)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects more than 10000 parts', function () {
    directInitiate($this, 10_001 * DIRECT_PART)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects the server chunk endpoint for direct uploads', function () {
    $uploadId = (string) directInitiate($this, DIRECT_PART)->json('upload_id');

    chunkyChunk($this, $uploadId, 0, 'bytes')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_state');
});

it('issues fresh part URLs on demand', function () {
    $uploadId = (string) directInitiate($this, 3 * DIRECT_PART)->json('upload_id');

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/part-urls", ['indexes' => [1, 2]]);

    $response->assertOk()->assertJsonStructure(['part_urls', 'expires_at']);
    expect((string) $response->getContent())->toContain('"part_urls":{"1":');
    expect(array_keys($response->json('part_urls')))->toBe([1, 2]);
});

it('rejects part URL requests for out-of-range indexes', function () {
    $uploadId = (string) directInitiate($this, 3 * DIRECT_PART)->json('upload_id');

    $this->postJson("/api/chunky/upload/{$uploadId}/part-urls", ['indexes' => [3]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'chunk_index_out_of_range');
});

it('rejects part URL requests on a server-transport upload', function () {
    config()->set('chunky.chunks.size', 8);
    $uploadId = (string) chunkyInitiate($this, 'file.bin', 20)->json('upload_id');

    $this->postJson("/api/chunky/upload/{$uploadId}/part-urls", ['indexes' => [0]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_state');
});

it('completes the remote upload and runs the profile hook', function () {
    Event::fake([FileAssembled::class, UploadCompleted::class]);
    $this->transport->objectSize = 3 * DIRECT_PART;

    $uploadId = (string) directInitiate($this, 3 * DIRECT_PART)->json('upload_id');

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/complete", ['parts' => directParts(3)]);

    $response->assertOk()
        ->assertJson(['status' => 'completed', 'progress' => 100.0])
        ->assertJsonPath('payload.stored', fn (string $path) => str_starts_with($path, 'direct-files/'));

    expect($this->transport->completed['remote-1'])->toBe([0 => '"etag-0"', 1 => '"etag-1"', 2 => '"etag-2"']);

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJson(['status' => 'completed']);

    Event::assertDispatched(FileAssembled::class);
    Event::assertDispatched(UploadCompleted::class);
});

it('replays completion idempotently', function () {
    $this->transport->objectSize = DIRECT_PART;
    $uploadId = (string) directInitiate($this, DIRECT_PART)->json('upload_id');

    $this->postJson("/api/chunky/upload/{$uploadId}/complete", ['parts' => directParts(1)])->assertOk();

    $this->postJson("/api/chunky/upload/{$uploadId}/complete", ['parts' => directParts(1)])
        ->assertOk()
        ->assertJson(['status' => 'completed']);
});

it('rejects completion with missing parts', function () {
    $uploadId = (string) directInitiate($this, 3 * DIRECT_PART)->json('upload_id');

    $this->postJson("/api/chunky/upload/{$uploadId}/complete", ['parts' => directParts(2)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('fails the upload when the remote size does not match', function () {
    Event::fake([UploadFailed::class]);
    $this->transport->objectSize = 123;

    $uploadId = (string) directInitiate($this, DIRECT_PART)->json('upload_id');

    $this->postJson("/api/chunky/upload/{$uploadId}/complete", ['parts' => directParts(1)])
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'assembly_failed');

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJson(['status' => 'failed']);

    Event::assertDispatched(UploadFailed::class);
});

it('fails the upload when CompleteMultipartUpload throws', function () {
    $this->transport->failComplete = true;

    $uploadId = (string) directInitiate($this, DIRECT_PART)->json('upload_id');

    $this->postJson("/api/chunky/upload/{$uploadId}/complete", ['parts' => directParts(1)])
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'assembly_failed');
});

it('aborts the remote upload on cancel', function () {
    $uploadId = (string) directInitiate($this, DIRECT_PART)->json('upload_id');

    $this->deleteJson("/api/chunky/upload/{$uploadId}")->assertOk();

    expect($this->transport->aborted)->toBe(['remote-1']);
});

it('aborts expired direct uploads during cleanup', function () {
    $uploadId = (string) directInitiate($this, DIRECT_PART)->json('upload_id');
    ChunkedUpload::query()->where('upload_id', $uploadId)->update(['expires_at' => now()->subDay()]);

    $this->artisan('chunky:cleanup')->assertExitCode(0);

    expect($this->transport->aborted)->toBe(['remote-1']);
    expect(ChunkedUpload::query()->find($uploadId))->toBeNull();
});

it('resumes a direct upload from the remote part list', function () {
    $first = directInitiate($this, 3 * DIRECT_PART, ['fingerprint' => 'fp-direct']);
    $first->assertStatus(201);

    $this->transport->remoteParts['remote-1'] = [0 => '"etag-0"'];

    $resumed = directInitiate($this, 3 * DIRECT_PART, ['fingerprint' => 'fp-direct']);

    $resumed->assertOk()
        ->assertJson(['resumed' => true, 'uploaded_chunks' => [0]])
        ->assertJsonPath('transport.mode', 'direct_s3');

    // Fresh URLs only for the missing parts; ETags for the uploaded ones so
    // the client can still build the full part list for complete.
    expect(array_keys($resumed->json('transport.part_urls')))->toBe([1, 2]);
    expect($resumed->json('transport.uploaded_parts'))->toBe([0 => '"etag-0"']);
});

it('does not call ListParts on the status endpoint', function () {
    $uploadId = (string) directInitiate($this, 3 * DIRECT_PART)->json('upload_id');
    $this->transport->listPartsCalls = 0;

    $this->getJson("/api/chunky/upload/{$uploadId}")->assertOk();

    expect($this->transport->listPartsCalls)->toBe(0);
});

it('reports a usable direct transport in doctor', function () {
    $this->artisan('chunky:doctor')
        ->expectsOutputToContain("direct_s3 transport can presign part URLs on disk 's3'.")
        ->assertExitCode(0);
});

it('aborts remote uploads when their batch is cancelled', function () {
    $batchId = (string) $this->postJson('/api/chunky/batch', ['total_files' => 1, 'profile' => 'direct'])
        ->assertStatus(201)
        ->json('batch_id');

    $this->postJson("/api/chunky/batch/{$batchId}/upload", ['file_name' => 'video.mp4', 'file_size' => DIRECT_PART])
        ->assertStatus(201);

    $this->deleteJson("/api/chunky/batch/{$batchId}")->assertOk();

    expect($this->transport->aborted)->toBe(['remote-1']);
});

it('rejects a negative part index at the service level', function () {
    $uploadId = (string) directInitiate($this, DIRECT_PART)->json('upload_id');

    app(DirectUploadService::class)->partUrls($uploadId, [-1]);
})->throws(ChunkIndexOutOfRangeException::class);
