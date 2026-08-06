<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Events\FileAssembled;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\UploadFailed;
use NETipar\Chunky\Jobs\AssembleFileJob;
use NETipar\Chunky\Profiles\CompletedUpload;
use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Profiles\UploadContext;
use NETipar\Chunky\Profiles\UploadProfile;
use NETipar\Chunky\Tests\Doubles\TestProfile;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

const ASSEMBLY_CONTENT = 'HELLO-CHUNKY-WORLD-!'; // 20 bytes -> 3 chunks of size 8

it('assembles synchronously and returns the completed result on the final chunk', function () {
    Event::fake([UploadCompleted::class, FileAssembled::class]);

    ['upload_id' => $uploadId, 'response' => $response] = chunkyCompleteUpload($this, ASSEMBLY_CONTENT);

    $response->assertOk()
        ->assertJson(['status' => 'completed', 'progress' => 100.0])
        ->assertJsonStructure(['status', 'progress', 'file' => ['path', 'size']]);

    $path = (string) $response->json('file.path');
    expect($response->json('file.size'))->toBe(20);
    Storage::disk('local')->assertExists($path);
    expect(Storage::disk('local')->get($path))->toBe(ASSEMBLY_CONTENT);

    Event::assertDispatched(FileAssembled::class);
    Event::assertDispatched(UploadCompleted::class);

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJson(['status' => 'completed'])
        ->assertJsonPath('file.path', $path);
});

it('runs the profile completed() hook and returns its payload', function () {
    app(ProfileRegistry::class)->register('test', new TestProfile);

    ['response' => $response] = chunkyCompleteUpload($this, ASSEMBLY_CONTENT, ['profile' => 'test']);

    $response->assertOk()
        ->assertJson(['status' => 'completed', 'payload' => ['media_id' => 123]]);

    expect((string) $response->json('file.path'))->toStartWith('test-uploads/');
});

it('queues assembly and returns assembling when mode is queue', function () {
    config(['chunky.assembly.mode' => 'queue']);
    Queue::fake();

    ['response' => $response] = chunkyCompleteUpload($this, ASSEMBLY_CONTENT);

    $response->assertOk()->assertJson(['status' => 'assembling', 'progress' => 100.0]);
    Queue::assertPushed(AssembleFileJob::class);
});

it('fails a sync assembly when the profile hook throws, marking the upload failed', function () {
    app(ProfileRegistry::class)->register('boom', new class extends UploadProfile
    {
        public function directory(UploadContext $context): string
        {
            return 'boom';
        }

        public function completed(CompletedUpload $upload): ?array
        {
            throw new RuntimeException('hook exploded');
        }
    });

    Event::fake([UploadFailed::class]);

    ['upload_id' => $uploadId, 'response' => $response] = chunkyCompleteUpload($this, ASSEMBLY_CONTENT, ['profile' => 'boom']);

    $response->assertStatus(500)->assertJsonPath('error.code', 'assembly_failed');

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertOk()
        ->assertJson(['status' => 'failed']);

    Event::assertDispatched(UploadFailed::class);
});
