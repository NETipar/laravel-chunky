<?php

declare(strict_types=1);

namespace NETipar\Chunky\Contracts;

use Illuminate\Http\UploadedFile;

interface ChunkHandler
{
    public function store(string $uploadId, int $chunkIndex, UploadedFile $chunk): void;

    /**
     * Assemble all chunks into a final file.
     *
     * @return string The final file path on the configured disk.
     */
    public function assemble(string $uploadId, string $fileName, int $totalChunks): string;

    public function cleanup(string $uploadId): void;
}
