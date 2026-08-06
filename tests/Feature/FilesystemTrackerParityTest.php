<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

// The full HTTP lifecycle must work identically on the filesystem tracker.
beforeEach(function () {
    Storage::fake('local');
    config(['chunky.tracker' => 'filesystem']);
});

it('drives a full sync upload on the filesystem tracker', function () {
    ['upload_id' => $uploadId, 'response' => $response] = chunkyCompleteUpload($this, 'HELLO-CHUNKY-WORLD-!');

    $response->assertOk()->assertJson(['status' => 'completed']);
    $this->getJson("/api/chunky/upload/{$uploadId}")->assertOk()->assertJson(['status' => 'completed']);
});

it('cancels an upload on the filesystem tracker', function () {
    $uploadId = (string) chunkyInitiate($this, 'f.bin', 20)->assertStatus(201)->json('upload_id');
    chunkyChunk($this, $uploadId, 0, '01234567')->assertOk();

    $this->deleteJson("/api/chunky/upload/{$uploadId}")->assertOk()->assertExactJson(['status' => 'cancelled']);
    $this->getJson("/api/chunky/upload/{$uploadId}")->assertOk()->assertJson(['status' => 'cancelled']);
});
