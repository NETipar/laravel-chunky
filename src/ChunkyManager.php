<?php

declare(strict_types=1);

namespace NETipar\Chunky;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatusResult;
use NETipar\Chunky\Domain\ChunkUploadOutcome;
use NETipar\Chunky\Domain\InitiateResult;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Profiles\UploadProfile;
use NETipar\Chunky\Services\BatchService;
use NETipar\Chunky\Services\InitiateInput;
use NETipar\Chunky\Services\UploadService;

/**
 * The public entrypoint behind the Chunky facade. A thin convenience layer over
 * the services; server-side callers can also inject the services directly.
 */
final class ChunkyManager
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly BatchService $batches,
        private readonly ProfileRegistry $profiles,
    ) {}

    public function initiate(InitiateInput $input): InitiateResult
    {
        return $this->uploads->initiate($input);
    }

    public function uploadChunk(string $uploadId, int $chunkIndex, UploadedFile $chunk): ChunkUploadOutcome
    {
        return $this->uploads->uploadChunk($uploadId, $chunkIndex, $chunk);
    }

    public function status(string $uploadId): ?UploadRecord
    {
        return $this->uploads->find($uploadId);
    }

    public function cancel(string $uploadId): UploadRecord
    {
        return $this->uploads->cancel($uploadId);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initiateBatch(int $totalFiles, ?string $profile = null, array $metadata = [], ?Authenticatable $user = null): BatchRecord
    {
        return $this->batches->initiate($totalFiles, $profile, $metadata, $user);
    }

    public function batchStatus(string $batchId): BatchStatusResult
    {
        return $this->batches->status($batchId);
    }

    public function cancelBatch(string $batchId): void
    {
        $this->batches->cancel($batchId);
    }

    /**
     * @param  UploadProfile|class-string|Closure  $profile
     */
    public function registerProfile(string $name, UploadProfile|string|Closure $profile): void
    {
        $this->profiles->register($name, $profile);
    }

    /**
     * @param  array{disk?: string, max_size?: int, mimes?: list<string>}  $options
     */
    public function simple(string $name, string $directory, array $options = []): void
    {
        $this->profiles->simple($name, $directory, $options);
    }

    public function profiles(): ProfileRegistry
    {
        return $this->profiles;
    }
}
