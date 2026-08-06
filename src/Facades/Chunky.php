<?php

declare(strict_types=1);

namespace NETipar\Chunky\Facades;

use Illuminate\Support\Facades\Facade;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatusResult;
use NETipar\Chunky\Domain\ChunkUploadOutcome;
use NETipar\Chunky\Domain\InitiateResult;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Services\InitiateInput;

/**
 * @method static InitiateResult initiate(InitiateInput $input)
 * @method static ChunkUploadOutcome uploadChunk(string $uploadId, int $chunkIndex, \Illuminate\Http\UploadedFile $chunk)
 * @method static UploadRecord|null status(string $uploadId)
 * @method static UploadRecord cancel(string $uploadId)
 * @method static BatchRecord initiateBatch(int $totalFiles, ?string $profile = null, array<string, mixed> $metadata = [], ?\Illuminate\Contracts\Auth\Authenticatable $user = null)
 * @method static BatchStatusResult batchStatus(string $batchId)
 * @method static void cancelBatch(string $batchId)
 * @method static void registerProfile(string $name, mixed $profile)
 * @method static void simple(string $name, string $directory, array<string, mixed> $options = [])
 *
 * @see ChunkyManager
 */
final class Chunky extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ChunkyManager::class;
    }
}
