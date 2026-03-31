<?php

use Illuminate\Support\Facades\Event;
use NETipar\Chunky\Events\UploadInitiated;

it('initiates an upload via the API', function () {
    Event::fake([UploadInitiated::class]);

    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'large-file.pdf',
        'file_size' => 5 * 1024 * 1024,
        'mime_type' => 'application/pdf',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['upload_id', 'chunk_size', 'total_chunks'])
        ->assertJson([
            'chunk_size' => 1024 * 1024,
            'total_chunks' => 5,
        ]);

    Event::assertDispatched(UploadInitiated::class);
});

it('validates required fields', function () {
    $response = $this->postJson('/api/chunky/upload', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file_name', 'file_size']);
});

it('validates file size must be positive', function () {
    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'test.txt',
        'file_size' => 0,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file_size']);
});

it('validates max file size when configured', function () {
    config(['chunky.max_file_size' => 1024 * 1024]); // 1MB

    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'test.txt',
        'file_size' => 2 * 1024 * 1024,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file_size']);
});

it('validates allowed mime types when configured', function () {
    config(['chunky.allowed_mimes' => ['image/jpeg', 'image/png']]);

    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'test.pdf',
        'file_size' => 1000,
        'mime_type' => 'application/pdf',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['mime_type']);
});

it('accepts metadata', function () {
    Event::fake([UploadInitiated::class]);

    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'test.pdf',
        'file_size' => 1000,
        'metadata' => ['folder' => 'documents'],
    ]);

    $response->assertStatus(201);
});

it('accepts context parameter', function () {
    Event::fake([UploadInitiated::class]);

    $manager = app(\NETipar\Chunky\ChunkyManager::class);
    $manager->context('profile_avatar');

    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'avatar.jpg',
        'file_size' => 50000,
        'mime_type' => 'image/jpeg',
        'context' => 'profile_avatar',
    ]);

    $response->assertStatus(201);
});

it('rejects unregistered context', function () {
    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'test.txt',
        'file_size' => 1000,
        'context' => 'nonexistent_context',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['context']);
});

it('merges context validation rules', function () {
    $manager = app(\NETipar\Chunky\ChunkyManager::class);
    $manager->context('strict', rules: fn () => [
        'file_size' => ['max:1000'],
    ]);

    $response = $this->postJson('/api/chunky/upload', [
        'file_name' => 'test.txt',
        'file_size' => 2000,
        'context' => 'strict',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file_size']);
});
