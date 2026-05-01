<?php

declare(strict_types=1);

namespace NETipar\Chunky\Facades;

use Illuminate\Support\Facades\Facade;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Data\ChunkUploadResult;
use NETipar\Chunky\Data\InitiateResult;
use NETipar\Chunky\Data\UploadMetadata;

/**
 * @method static void register(string $contextClass)
 * @method static void simple(string $name, string $directory, array $options = [])
 * @method static void context(string $name, ?\Closure $rules = null, ?\Closure $save = null)
 * @method static array getContextRules(string $name)
 * @method static \Closure|null getContextSaveCallback(string $name)
 * @method static bool hasContext(string $name)
 * @method static InitiateResult initiate(string $fileName, int $fileSize, ?string $mimeType = null, array $metadata = [], ?string $context = null)
 * @method static ChunkUploadResult uploadChunk(string $uploadId, int $chunkIndex, \Illuminate\Http\UploadedFile $chunk)
 * @method static UploadMetadata|null status(string $uploadId)
 * @method static bool cancel(string $uploadId)
 * @method static BatchMetadata initiateBatch(int $totalFiles, ?string $context = null, array $metadata = [])
 * @method static InitiateResult initiateInBatch(string $batchId, string $fileName, int $fileSize, ?string $mimeType = null, array $metadata = [], ?string $context = null)
 * @method static BatchMetadata|null getBatchStatus(string $batchId)
 * @method static void markBatchUploadCompleted(string $batchId)
 * @method static void markBatchUploadFailed(string $batchId)
 * @method static ChunkHandler handler() @internal use through DI rather than the facade
 * @method static UploadTracker tracker() @internal use through DI rather than the facade
 *
 * @see ChunkyManager
 */
class Chunky extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ChunkyManager::class;
    }
}
