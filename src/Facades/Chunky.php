<?php

namespace NETipar\Chunky\Facades;

use Illuminate\Support\Facades\Facade;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Data\UploadMetadata;

/**
 * @method static void register(string $contextClass)
 * @method static void context(string $name, ?\Closure $rules = null, ?\Closure $save = null)
 * @method static array getContextRules(string $name)
 * @method static \Closure|null getContextSaveCallback(string $name)
 * @method static bool hasContext(string $name)
 * @method static array initiate(string $fileName, int $fileSize, ?string $mimeType = null, array $metadata = [], ?string $context = null)
 * @method static array uploadChunk(string $uploadId, int $chunkIndex, \Illuminate\Http\UploadedFile $chunk)
 * @method static UploadMetadata|null status(string $uploadId)
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
