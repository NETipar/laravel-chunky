<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

if (! function_exists('chunkyInitiate')) {
    /**
     * @param  array<string, mixed>  $extra
     */
    function chunkyInitiate(object $test, string $fileName, int $fileSize, array $extra = []): TestResponse
    {
        /** @var TestResponse $response */
        $response = $test->postJson('/api/chunky/upload', array_merge([
            'file_name' => $fileName,
            'file_size' => $fileSize,
        ], $extra));

        return $response;
    }
}

if (! function_exists('chunkyChunk')) {
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    function chunkyChunk(object $test, string $uploadId, int $index, string $bytes, array $extra = [], array $headers = []): TestResponse
    {
        /** @var TestResponse $response */
        $response = $test->post(
            "/api/chunky/upload/{$uploadId}/chunks",
            array_merge([
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes),
                'chunk_index' => $index,
            ], $extra),
            $headers,
        );

        return $response;
    }
}

if (! function_exists('chunkyCompleteUpload')) {
    /**
     * Drives a full upload (initiate + all chunks) and returns the upload id and
     * the final chunk response.
     *
     * @param  array<string, mixed>  $initiateExtra
     * @return array{upload_id: string, response: TestResponse}
     */
    function chunkyCompleteUpload(object $test, string $content, array $initiateExtra = [], bool $withChecksum = false): array
    {
        $initiate = chunkyInitiate($test, 'file.bin', strlen($content), $initiateExtra);
        $initiate->assertStatus(201);

        $uploadId = (string) $initiate->json('upload_id');
        $chunkSize = (int) $initiate->json('chunk_size');
        $totalChunks = (int) $initiate->json('total_chunks');

        $response = $initiate;
        for ($i = 0; $i < $totalChunks; $i++) {
            $bytes = substr($content, $i * $chunkSize, $chunkSize);
            $extra = $withChecksum ? ['checksum' => hash('sha256', $bytes)] : [];
            $response = chunkyChunk($test, $uploadId, $i, $bytes, $extra);
        }

        return ['upload_id' => $uploadId, 'response' => $response];
    }
}
