<?php

namespace NETipar\Chunky\Handlers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\ChunkHandler;

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

    public function assemble(string $uploadId, string $fileName, int $totalChunks): string
    {
        // Defence-in-depth against path traversal even if validation was
        // bypassed: basename() strips any leading directory components, and
        // the leading "." / ".." cases are rejected outright.
        $safeFileName = basename($fileName);

        if ($safeFileName === '' || $safeFileName === '.' || $safeFileName === '..') {
            throw new \RuntimeException("Refusing to assemble upload {$uploadId}: invalid file name.");
        }

        $finalPath = config('chunky.final_directory')."/{$uploadId}/{$safeFileName}";
        $disk = $this->disk();

        $stagingDir = $this->resolveStagingDirectory();
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
        $tempDir = config('chunky.temp_directory')."/{$uploadId}";

        $this->disk()->deleteDirectory($tempDir);
    }

    private function chunkPath(string $uploadId, int $chunkIndex): string
    {
        return config('chunky.temp_directory')."/{$uploadId}/chunk_{$chunkIndex}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('chunky.disk'));
    }

    private function resolveStagingDirectory(): string
    {
        $configured = config('chunky.staging_directory');

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
