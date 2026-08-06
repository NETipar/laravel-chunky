<?php

declare(strict_types=1);

namespace NETipar\Chunky\Support;

final class ChunkCalculator
{
    public static function totalChunks(int $fileSize, int $chunkSize): int
    {
        if ($chunkSize <= 0) {
            return 0;
        }

        return (int) ceil($fileSize / $chunkSize);
    }

    public static function progress(int $uploadedChunks, int $totalChunks): float
    {
        if ($totalChunks <= 0) {
            return 0.0;
        }

        return round($uploadedChunks / $totalChunks * 100, 2);
    }
}
