<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

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

it('answers a non-owner reading another upload with 404', function () {
    $this->actingAs(makeUser(1));
    $uploadId = (string) chunkyInitiate($this, 'private.pdf', 20)->assertStatus(201)->json('upload_id');

    $this->actingAs(makeUser(2));
    $this->getJson("/api/chunky/upload/{$uploadId}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'upload_not_found');
});

it('answers a non-owner cancelling another upload with 404', function () {
    $this->actingAs(makeUser(3));
    $uploadId = (string) chunkyInitiate($this, 'private.pdf', 20)->assertStatus(201)->json('upload_id');

    $this->actingAs(makeUser(4));
    $this->deleteJson("/api/chunky/upload/{$uploadId}")->assertStatus(404);
});

it('rejects a non-owner posting chunks with 403', function () {
    $this->actingAs(makeUser(5));
    $uploadId = (string) chunkyInitiate($this, 'private.bin', 20)->assertStatus(201)->json('upload_id');

    $this->actingAs(makeUser(6));
    chunkyChunk($this, $uploadId, 0, '01234567')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'unauthorized');
});

it('lets the owner access their own upload', function () {
    $this->actingAs(makeUser(7));
    $uploadId = (string) chunkyInitiate($this, 'mine.pdf', 20)->assertStatus(201)->json('upload_id');

    $this->getJson("/api/chunky/upload/{$uploadId}")->assertOk();
});

it('keeps anonymous uploads accessible without auth', function () {
    $uploadId = (string) chunkyInitiate($this, 'anon.pdf', 20)->assertStatus(201)->json('upload_id');

    $this->getJson("/api/chunky/upload/{$uploadId}")->assertOk();
});

it('answers a non-owner initiating a batch member with 404', function () {
    $this->actingAs(makeUser(8));
    $batchId = (string) $this->postJson('/api/chunky/batch', ['total_files' => 2])
        ->assertStatus(201)
        ->json('batch_id');

    $this->actingAs(makeUser(9));
    $this->postJson("/api/chunky/batch/{$batchId}/upload", ['file_name' => 'a.bin', 'file_size' => 20])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'batch_not_found');
});

it('lets the owner initiate a member on their own batch', function () {
    $this->actingAs(makeUser(10));
    $batchId = (string) $this->postJson('/api/chunky/batch', ['total_files' => 2])
        ->assertStatus(201)
        ->json('batch_id');

    $this->postJson("/api/chunky/batch/{$batchId}/upload", ['file_name' => 'a.bin', 'file_size' => 20])
        ->assertStatus(201);
});
