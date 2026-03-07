<?php

namespace NETipar\Chunky\Handlers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NETipar\Chunky\Contracts\ChunkHandler;

class DefaultChunkHandler implements ChunkHandler
{
    public function store(string $uploadId, int $chunkIndex, UploadedFile $chunk): void
    {
        $path = $this->chunkPath($uploadId, $chunkIndex);

        $this->disk()->put($path, $chunk->getContent());
    }

    public function assemble(string $uploadId, string $fileName, int $totalChunks): string
    {
        $finalPath = config('chunky.final_directory')."/{$uploadId}/{$fileName}";
        $tempFilePath = $this->chunkPath($uploadId, 0);

        // Use stream to assemble for memory efficiency
        $finalFullPath = $this->disk()->path($finalPath);
        $directory = dirname($finalFullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $outputStream = fopen($finalFullPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $this->chunkPath($uploadId, $i);
            $chunkFullPath = $this->disk()->path($chunkPath);

            $inputStream = fopen($chunkFullPath, 'rb');

            while (! feof($inputStream)) {
                fwrite($outputStream, fread($inputStream, 8192));
            }

            fclose($inputStream);
        }

        fclose($outputStream);

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

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('chunky.disk'));
    }
}
