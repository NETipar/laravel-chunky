<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Domain\CompletedFile;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadResult;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Events\FileAssembled;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\UploadFailed;
use NETipar\Chunky\Exceptions\AssemblyFailedException;
use NETipar\Chunky\Exceptions\ChunkIndexOutOfRangeException;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\InvalidDirectUploadException;
use NETipar\Chunky\Exceptions\InvalidStateException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Exceptions\UploadNotFoundException;
use NETipar\Chunky\Ports\Clock;
use NETipar\Chunky\Ports\DirectUploadTransport;
use NETipar\Chunky\Ports\UploadRepository;
use NETipar\Chunky\Profiles\CompletedUpload;
use NETipar\Chunky\Profiles\ProfileRegistry;
use Throwable;

/**
 * Orchestration for the direct_s3 transport: remote multipart lifecycle,
 * presigned part URL issuing, and the completion "remote assembly" (state
 * machine + integrity + profile hook + events) — S3 does the merging.
 */
final class DirectUploadService
{
    public const int MIN_PART_SIZE = 5 * 1024 * 1024;

    public const int MAX_PARTS = 10_000;

    public const int URL_BATCH = 100;

    public function __construct(
        private readonly Container $container,
        private readonly UploadRepository $uploads,
        private readonly ChunkyConfig $config,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly ProfileRegistry $profiles,
        private readonly BatchService $batches,
        private readonly FilesystemFactory $filesystem,
    ) {}

    /**
     * Enforce the S3 multipart constraints at initiate time.
     */
    public function assertConstraints(int $chunkSize, int $totalChunks): void
    {
        if ($chunkSize < self::MIN_PART_SIZE) {
            throw InvalidDirectUploadException::partTooSmall($chunkSize);
        }

        if ($totalChunks > self::MAX_PARTS) {
            throw InvalidDirectUploadException::tooManyParts($totalChunks);
        }
    }

    /**
     * Start the remote multipart upload for a fresh record.
     */
    public function createRemote(string $finalPath, ?string $mimeType): string
    {
        return $this->transport()->create($finalPath, $mimeType);
    }

    /**
     * The `transport` object embedded in initiate responses: the first batch
     * of presigned URLs for the given (not-yet-uploaded) indexes. On resume,
     * $uploadedParts carries the ETags of already-uploaded parts so the client
     * can still assemble the full part list for complete.
     *
     * @param  list<int>  $missingIndexes
     * @param  array<int, string>|null  $uploadedParts
     * @return array<string, mixed>
     */
    public function transportPayload(UploadRecord $record, array $missingIndexes, ?array $uploadedParts = null): array
    {
        $batch = array_slice($missingIndexes, 0, self::URL_BATCH);

        $payload = [
            'mode' => 'direct_s3',
            'part_urls' => $this->presign($record, $batch),
            'expires_at' => $this->expiresAt()->format(DateTimeInterface::ATOM),
        ];

        if ($uploadedParts !== null) {
            $payload['uploaded_parts'] = (object) $uploadedParts;
        }

        return $payload;
    }

    /**
     * Already-uploaded parts of a resumable direct upload, from the remote
     * ListParts (best effort — falls back to the tracker's state with
     * unknown ETags).
     *
     * @return array<int, string> index => ETag
     */
    public function uploadedParts(UploadRecord $record): array
    {
        try {
            $parts = $this->transport()->listParts((string) $record->finalPath, (string) $record->remoteUploadId);
            ksort($parts);

            return $parts;
        } catch (Throwable) {
            return array_fill_keys($record->uploadedChunks, '');
        }
    }

    /**
     * Fresh presigned URLs for explicitly requested indexes (expired URLs,
     * retry, resume).
     *
     * @param  list<int>  $indexes
     * @return array{part_urls: object, expires_at: string}
     */
    public function partUrls(string $uploadId, array $indexes): array
    {
        $record = $this->directRecord($uploadId);

        foreach ($indexes as $index) {
            if ($index < 0 || $index >= $record->totalChunks) {
                throw ChunkIndexOutOfRangeException::make($index, $record->totalChunks);
            }
        }

        return [
            'part_urls' => $this->presign($record, array_slice($indexes, 0, self::URL_BATCH)),
            'expires_at' => $this->expiresAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Complete the remote upload: CompleteMultipartUpload, then the remote
     * assembly pipeline (integrity, profile hook, terminal state, events).
     *
     * @param  array<int, string>  $parts  index => ETag
     */
    public function complete(string $uploadId, array $parts): UploadResult
    {
        $record = $this->directRecord($uploadId, allowCompleted: true);

        if ($record->status === UploadStatus::Completed) {
            return $this->resultFor($record);
        }

        if (count($parts) !== $record->totalChunks) {
            throw InvalidDirectUploadException::incompleteParts(count($parts), $record->totalChunks);
        }

        foreach (array_keys($parts) as $index) {
            if ($index < 0 || $index >= $record->totalChunks) {
                throw ChunkIndexOutOfRangeException::make($index, $record->totalChunks);
            }
        }

        $claimed = $this->uploads->transition($uploadId, UploadStatus::Pending, UploadStatus::Assembling)
            || $this->uploads->transition($uploadId, UploadStatus::Uploading, UploadStatus::Assembling);

        if (! $claimed) {
            throw InvalidStateException::upload($record->status, UploadStatus::Assembling);
        }

        try {
            $payload = $this->finishRemote($record, $parts);
        } catch (Throwable $e) {
            $this->fail($record, $e->getMessage());

            throw AssemblyFailedException::forUpload($uploadId, $e);
        }

        $this->uploads->transition($uploadId, UploadStatus::Assembling, UploadStatus::Completed, [
            'final_path' => $record->finalPath,
            'result_payload' => $payload,
        ]);

        $completed = $this->uploads->find($uploadId) ?? $record;
        $this->events->dispatch(new FileAssembled($completed));
        $this->events->dispatch(new UploadCompleted($completed));
        $this->batches->onUploadCompleted($completed);

        return $this->resultFor($completed);
    }

    /**
     * Best-effort AbortMultipartUpload for cancel/cleanup paths.
     */
    public function abortRemote(UploadRecord $record): void
    {
        if (! $record->isDirect() || $record->remoteUploadId === null) {
            return;
        }

        try {
            $this->transport()->abort((string) $record->finalPath, $record->remoteUploadId);
        } catch (Throwable) {
            // Best effort; an S3 AbortIncompleteMultipartUpload lifecycle rule
            // is the recommended safety net.
        }
    }

    private function transport(): DirectUploadTransport
    {
        return $this->container->make(DirectUploadTransport::class);
    }

    /**
     * @param  array<int, string>  $parts
     * @return array<string, mixed>|null
     */
    private function finishRemote(UploadRecord $record, array $parts): ?array
    {
        $key = (string) $record->finalPath;
        $this->transport()->complete($key, (string) $record->remoteUploadId, $parts);

        $actual = $this->transport()->size($key);

        if ($record->fileSize > 0 && $actual !== $record->fileSize) {
            throw new ChunkyException(
                "Remote object size {$actual} does not match expected {$record->fileSize} for upload '{$record->uploadId}'.",
            );
        }

        $profile = $this->profiles->resolve($record->profile);

        return $profile?->completed(new CompletedUpload(
            uploadId: $record->uploadId,
            disk: $record->disk,
            path: $key,
            fileName: $record->fileName,
            fileSize: $record->fileSize,
            mimeType: $record->mimeType,
            metadata: $record->metadata,
            userId: $record->userId,
            batchId: $record->batchId,
        ));
    }

    private function directRecord(string $uploadId, bool $allowCompleted = false): UploadRecord
    {
        $record = $this->uploads->find($uploadId);

        if ($record === null) {
            throw UploadNotFoundException::forUpload($uploadId);
        }

        if (! $record->isDirect()) {
            throw InvalidStateException::upload($record->status, UploadStatus::Assembling);
        }

        if (! $record->status->isTerminal() && $record->isExpiredAt($this->clock->now())) {
            throw UploadExpiredException::forUpload($uploadId);
        }

        if ($record->status->isTerminal() && ! ($allowCompleted && $record->status === UploadStatus::Completed)) {
            throw InvalidStateException::upload($record->status, UploadStatus::Assembling);
        }

        return $record;
    }

    /**
     * PHP canonicalizes numeric string keys back to int, so the JSON-object
     * shape (`{"0": url}`) is forced with an object cast — a sequential array
     * would otherwise serialize as a JSON array.
     *
     * @param  list<int>  $indexes
     */
    private function presign(UploadRecord $record, array $indexes): object
    {
        return (object) $this->transport()->presignParts(
            (string) $record->finalPath,
            (string) $record->remoteUploadId,
            $indexes,
        );
    }

    private function expiresAt(): DateTimeImmutable
    {
        return $this->clock->now()->add(new DateInterval("PT{$this->config->directS3UrlTtl}S"));
    }

    private function fail(UploadRecord $record, string $reason): void
    {
        if ($this->uploads->transition($record->uploadId, UploadStatus::Assembling, UploadStatus::Failed)) {
            $failed = $this->uploads->find($record->uploadId) ?? $record;
            $this->events->dispatch(new UploadFailed($failed, $reason));
            $this->batches->onUploadFailed($failed);
        }
    }

    private function resultFor(UploadRecord $record): UploadResult
    {
        $file = null;

        if ($record->status === UploadStatus::Completed && $record->finalPath !== null) {
            $file = new CompletedFile($record->finalPath, $record->fileSize, $this->publicUrl($record->disk, $record->finalPath));
        }

        return new UploadResult($record->uploadId, $record->status, $file, $record->resultPayload);
    }

    private function publicUrl(string $disk, string $path): ?string
    {
        try {
            return $this->filesystem->disk($disk)->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
