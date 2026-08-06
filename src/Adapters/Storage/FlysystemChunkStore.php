<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\ChunkStore;

final class FlysystemChunkStore implements ChunkStore
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $directory = 'chunky/chunks',
    ) {}

    public function put(string $uploadId, int $chunkIndex, UploadedFile $chunk): void
    {
        $stored = $this->disk->putFileAs($this->chunkDir($uploadId), $chunk, $this->chunkName($chunkIndex));

        if ($stored === false) {
            throw new ChunkyException("Failed to store chunk {$chunkIndex} for upload '{$uploadId}'.");
        }
    }

    public function exists(string $uploadId, int $chunkIndex): bool
    {
        return $this->disk->exists($this->chunkPath($uploadId, $chunkIndex));
    }

    /**
     * @return resource A readable stream for the chunk.
     */
    public function readStream(string $uploadId, int $chunkIndex) // @pest-ignore-type
    {
        $stream = $this->disk->readStream($this->chunkPath($uploadId, $chunkIndex));

        if (! is_resource($stream)) {
            throw new ChunkyException("Chunk {$chunkIndex} for upload '{$uploadId}' could not be read.");
        }

        return $stream;
    }

    public function purge(string $uploadId): void
    {
        $this->disk->deleteDirectory($this->chunkDir($uploadId));
    }

    private function chunkDir(string $uploadId): string
    {
        return $this->directory.'/'.$this->safeId($uploadId);
    }

    private function chunkPath(string $uploadId, int $chunkIndex): string
    {
        return $this->chunkDir($uploadId).'/'.$this->chunkName($chunkIndex);
    }

    private function chunkName(int $chunkIndex): string
    {
        return 'chunk_'.$chunkIndex;
    }

    private function safeId(string $uploadId): string
    {
        if ($uploadId === ''
            || str_contains($uploadId, '/')
            || str_contains($uploadId, '\\')
            || str_contains($uploadId, '..')
            || str_contains($uploadId, "\0")) {
            throw new ChunkyException("Unsafe upload id '{$uploadId}'.");
        }

        return $uploadId;
    }
}
