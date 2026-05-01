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

        $this->disk()->put($path, $chunk->getContent());
    }

    public function assemble(string $uploadId, string $fileName, int $totalChunks): string
    {
        $finalPath = config('chunky.final_directory')."/{$uploadId}/{$fileName}";
        $disk = $this->disk();

        $tempFile = tempnam(sys_get_temp_dir(), 'chunky-');
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
}
