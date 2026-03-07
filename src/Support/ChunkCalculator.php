<?php

namespace NETipar\Chunky\Support;

class ChunkCalculator
{
    public static function totalChunks(int $fileSize, int $chunkSize): int
    {
        return (int) ceil($fileSize / $chunkSize);
    }

    public static function chunkSize(?int $override = null): int
    {
        return $override ?? config('chunky.chunk_size', 1024 * 1024);
    }

    public static function progress(int $uploadedCount, int $totalChunks): float
    {
        if ($totalChunks <= 0) {
            return 0;
        }

        return round($uploadedCount / $totalChunks * 100, 2);
    }
}
