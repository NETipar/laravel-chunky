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
     * @param  ?int  $expectedSize  Total bytes the final file is expected
     *                              to be. Used for disk-space pre-flight
     *                              and post-write integrity assertion. Pass
     *                              null to skip the checks (back-compat).
     * @return string The final file path on the configured disk.
     */
    public function assemble(string $uploadId, string $fileName, int $totalChunks, ?int $expectedSize = null): string;

    public function cleanup(string $uploadId): void;
}
