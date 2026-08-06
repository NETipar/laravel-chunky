<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Models\ChunkedUpload;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function seedExpiredUpload(object $test, string $fileName = 'old.bin'): string
{
    $uploadId = (string) chunkyInitiate($test, $fileName, 20)->assertStatus(201)->json('upload_id');
    chunkyChunk($test, $uploadId, 0, '01234567')->assertOk();
    ChunkedUpload::query()->where('upload_id', $uploadId)->update(['expires_at' => now()->subDay()]);

    return $uploadId;
}

it('removes expired uploads and their chunks', function () {
    $expired = seedExpiredUpload($this);
    $fresh = (string) chunkyInitiate($this, 'keep.bin', 20)->assertStatus(201)->json('upload_id');

    $this->artisan('chunky:cleanup')->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $expired)->exists())->toBeFalse();
    expect(ChunkedUpload::query()->where('upload_id', $fresh)->exists())->toBeTrue();
    Storage::disk('local')->assertMissing("chunky/chunks/{$expired}");
});

it('lists removable uploads in dry-run without deleting', function () {
    $expired = seedExpiredUpload($this);

    $this->artisan('chunky:cleanup --dry-run')
        ->expectsOutputToContain("Would remove upload: {$expired}")
        ->assertExitCode(0);

    expect(ChunkedUpload::query()->where('upload_id', $expired)->exists())->toBeTrue();
});

it('reports nothing to remove when none are expired', function () {
    chunkyInitiate($this, 'keep.bin', 20)->assertStatus(201);

    $this->artisan('chunky:cleanup')
        ->expectsOutputToContain('No expired uploads found.')
        ->assertExitCode(0);
});
