<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use NETipar\Chunky\ChunkyManager;

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
});

function makeUser(int $id): User
{
    $user = new class extends User
    {
        protected $table = 'users';

        protected $guarded = [];
    };

    $user->forceFill(['id' => $id, 'name' => "User {$id}"])->save();

    return $user;
}

it('blocks non-owners from reading another user upload status', function () {
    $alice = makeUser(1);
    $bob = makeUser(2);

    $this->actingAs($alice);

    $initiate = $this->postJson('/api/chunky/upload', [
        'file_name' => 'private.pdf',
        'file_size' => 1024,
    ]);

    $initiate->assertStatus(201);
    $uploadId = $initiate->json('upload_id');

    // Bob attempts to read Alice's upload — should look like 404 to him so
    // we don't leak which upload IDs exist to non-owners.
    $this->actingAs($bob);

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertStatus(404);
});

it('blocks non-owners from cancelling another user upload', function () {
    $alice = makeUser(3);
    $bob = makeUser(4);

    $this->actingAs($alice);

    $initiate = $this->postJson('/api/chunky/upload', [
        'file_name' => 'private.pdf',
        'file_size' => 1024,
    ]);

    $uploadId = $initiate->json('upload_id');

    $this->actingAs($bob);

    $this->deleteJson("/api/chunky/upload/{$uploadId}")
        ->assertStatus(404);

    // Verify the upload is still active for the owner.
    $this->actingAs($alice);

    expect(app(ChunkyManager::class)->status($uploadId))->not->toBeNull();
});

it('blocks non-owners from POSTing chunks to another user upload', function () {
    $alice = makeUser(5);
    $bob = makeUser(6);

    $this->actingAs($alice);

    $initiate = $this->postJson('/api/chunky/upload', [
        'file_name' => 'private.bin',
        'file_size' => 1024,
    ]);

    $uploadId = $initiate->json('upload_id');

    $this->actingAs($bob);

    $chunk = UploadedFile::fake()->create('chunk', 1);

    $response = $this->postJson("/api/chunky/upload/{$uploadId}/chunks", [
        'chunk' => $chunk,
        'chunk_index' => 0,
    ]);

    // FormRequest::authorize() returning false yields 403.
    $response->assertStatus(403);
});

it('lets the owner access their own upload normally', function () {
    $alice = makeUser(7);

    $this->actingAs($alice);

    $initiate = $this->postJson('/api/chunky/upload', [
        'file_name' => 'mine.pdf',
        'file_size' => 1024,
    ]);

    $uploadId = $initiate->json('upload_id');

    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertStatus(200)
        ->assertJson(['upload_id' => $uploadId]);
});

it('keeps anonymous uploads accessible without auth (backward compat)', function () {
    // No actingAs — anonymous request, no user_id stored on the upload.
    $initiate = $this->postJson('/api/chunky/upload', [
        'file_name' => 'anon.pdf',
        'file_size' => 1024,
    ]);

    $uploadId = $initiate->json('upload_id');

    // Status is readable without auth, matching the v0.11 behaviour.
    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertStatus(200);
});
