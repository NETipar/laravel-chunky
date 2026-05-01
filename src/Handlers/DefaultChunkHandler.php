<?php

declare(strict_types=1);

namespace NETipar\Chunky\Handlers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Data\UploadMetadata;

class DefaultChunkHandler implements ChunkHandler
{
    public function store(string $uploadId, int $chunkIndex, UploadedFile $chunk): void
    {
        $path = $this->chunkPath($uploadId, $chunkIndex);
        $realPath = $chunk->getRealPath();

        if ($realPath !== false && is_file($realPath)) {
            $stream = fopen($realPath, 'rb');

            try {
                $this->disk()->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }

            return;
        }

        $this->disk()->put($path, $chunk->getContent());
    }

    public function assemble(UploadMetadata $metadata): string
    {
        $uploadId = $metadata->uploadId;
        $totalChunks = $metadata->totalChunks;
        $expectedSize = $metadata->fileSize;

        // Defence-in-depth against path traversal even if validation was
        // bypassed: basename() strips any leading directory components, and
        // the leading "." / ".." cases are rejected outright.
        $safeFileName = basename($metadata->fileName);

        if ($safeFileName === '' || $safeFileName === '.' || $safeFileName === '..') {
            throw new \RuntimeException("Refusing to assemble upload {$uploadId}: invalid file name.");
        }

        $finalPath = config('chunky.storage.final_directory')."/{$uploadId}/{$safeFileName}";
        $disk = $this->disk();

        $stagingDir = $this->resolveStagingDirectory();

        // Pre-flight: refuse to even start an assembly that the staging
        // volume can't hold. Lets a 100GB upload fail fast instead of
        // running fwrite() until the partition is full and crashing the
        // worker mid-file. Skipped for fileSize=0 (test fixtures, empty
        // file metadata).
        if ($expectedSize > 0) {
            $free = @disk_free_space($stagingDir);

            if ($free !== false && $free < (int) ($expectedSize * 1.1)) {
                throw new \RuntimeException(
                    "Insufficient staging disk space for upload {$uploadId}: "
                    ."need ~{$expectedSize} bytes (+10% margin), have {$free} bytes free in {$stagingDir}.",
                );
            }
        }

        $tempFile = tempnam($stagingDir, 'chunky-');

        if ($tempFile === false) {
            throw new \RuntimeException(
                "Failed to create staging temp file in '{$stagingDir}'. "
                .'Ensure the directory exists and is writable.',
            );
        }
        $output = fopen($tempFile, 'wb');

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $input = $disk->readStream($this->chunkPath($uploadId, $i));

                if ($input === false || $input === null) {
                    throw new \RuntimeException("Chunk {$i} for upload {$uploadId} could not be read.");
                }

                try {
                    while (! feof($input)) {
                        fwrite($output, fread($input, 8192));
                    }
                } finally {
                    fclose($input);
                }
            }

            fclose($output);
            $output = null;

            // Post-write integrity assertion: catch silent truncation
            // (race / chunk corruption / partial Storage write). Without
            // this, a missing chunk's bytes silently produce a smaller
            // file and the user gets a corrupted download.
            if ($expectedSize > 0) {
                $actualSize = @filesize($tempFile);

                if ($actualSize !== $expectedSize) {
                    @unlink($tempFile);

                    throw new \RuntimeException(
                        "Assembled size mismatch for {$uploadId}: "
                        ."expected {$expectedSize}, got "
                        .(is_int($actualSize) ? (string) $actualSize : 'unknown')
                        .'. Possible chunk corruption — refusing to publish.',
                    );
                }
            }

            $upload = fopen($tempFile, 'rb');

            try {
                $disk->writeStream($finalPath, $upload);
            } finally {
                fclose($upload);
            }
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }

            @unlink($tempFile);
        }

        return $finalPath;
    }

    public function cleanup(string $uploadId): void
    {
        $tempDir = config('chunky.storage.temp_directory')."/{$uploadId}";

        $this->disk()->deleteDirectory($tempDir);
    }

    private function chunkPath(string $uploadId, int $chunkIndex): string
    {
        return config('chunky.storage.temp_directory')."/{$uploadId}/chunk_{$chunkIndex}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('chunky.disk'));
    }

    private function resolveStagingDirectory(): string
    {
        $configured = config('chunky.storage.staging_directory');

        if (! $configured) {
            return sys_get_temp_dir();
        }

        if (! is_dir($configured) && ! @mkdir($configured, 0755, true) && ! is_dir($configured)) {
            throw new \RuntimeException(
                "Configured chunky.staging_directory '{$configured}' does not exist and could not be created.",
            );
        }

        return $configured;
    }
}
