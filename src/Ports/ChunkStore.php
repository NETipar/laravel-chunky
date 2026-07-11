<?php

declare(strict_types=1);

namespace NETipar\Chunky\Ports;

use Illuminate\Http\UploadedFile;

interface ChunkStore
{
    public function put(string $uploadId, int $chunkIndex, UploadedFile $chunk): void;

    public function exists(string $uploadId, int $chunkIndex): bool;

    /**
     * @return resource A readable stream for the chunk.
     */
    public function readStream(string $uploadId, int $chunkIndex);

    public function purge(string $uploadId): void;
}
