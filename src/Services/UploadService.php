<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services;

use DateInterval;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Domain\ChunkProgress;
use NETipar\Chunky\Domain\ChunkUploadOutcome;
use NETipar\Chunky\Domain\Fingerprint;
use NETipar\Chunky\Domain\InitiateResult;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\UploadCancelled;
use NETipar\Chunky\Events\UploadInitiated;
use NETipar\Chunky\Exceptions\ChunkIndexOutOfRangeException;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\InvalidConfigurationException;
use NETipar\Chunky\Exceptions\InvalidStateException;
use NETipar\Chunky\Exceptions\MissingFileChecksumException;
use NETipar\Chunky\Exceptions\UnauthorizedUploadException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Exceptions\UploadNotFoundException;
use NETipar\Chunky\Jobs\AssembleFileJob;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Ports\Clock;
use NETipar\Chunky\Ports\UploadRepository;
use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Profiles\UploadContext;
use NETipar\Chunky\Profiles\UploadProfile;
use NETipar\Chunky\Support\ChunkCalculator;
use NETipar\Chunky\Support\Coerce;

final class UploadService
{
    public function __construct(
        private readonly UploadRepository $uploads,
        private readonly ChunkStore $chunks,
        private readonly ProfileRegistry $profiles,
        private readonly ChunkyConfig $config,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly AssemblyRunner $assembler,
        private readonly DirectUploadService $direct,
    ) {}

    public function initiate(InitiateInput $input): InitiateResult
    {
        $profile = $this->profiles->resolve($input->profile);

        $context = new UploadContext(
            $input->user,
            $input->fileName,
            $input->fileSize,
            $input->mimeType,
            $input->metadata,
            $input->batchId,
        );

        if ($profile !== null && ! $profile->authorize($context)) {
            throw UnauthorizedUploadException::make();
        }

        $userId = Coerce::toNullableString($input->user?->getAuthIdentifier());
        $fingerprint = Fingerprint::fromString($input->fingerprint)?->value;

        if ($this->config->resumeFingerprint && $fingerprint !== null) {
            $existing = $this->uploads->findByFingerprint($fingerprint, $userId);

            if ($existing !== null && ! $existing->status->isTerminal()) {
                return $this->resumeResult($existing);
            }
        }

        $transport = $profile?->transport() ?? 'server';

        if (! in_array($transport, ['server', 'direct_s3'], true)) {
            throw new ChunkyException("Unknown upload transport '{$transport}'.");
        }

        $uploadId = (string) Str::uuid();
        $chunkSize = $this->config->chunkSize;
        $totalChunks = ChunkCalculator::totalChunks($input->fileSize, $chunkSize);
        $finalPath = $this->resolveFinalPath($uploadId, $profile, $context);

        $remoteUploadId = null;
        $disk = $profile?->disk() ?? $this->config->disk;

        if ($transport === 'direct_s3') {
            $this->direct->assertConstraints($chunkSize, $totalChunks);
            $disk = $this->config->directS3Disk
                ?? throw InvalidConfigurationException::forKey('transports.direct_s3.disk', 'must be set to use the direct_s3 transport.');
            $remoteUploadId = $this->direct->createRemote($finalPath, $input->mimeType);
        }

        $record = new UploadRecord(
            uploadId: $uploadId,
            fileName: $input->fileName,
            fileSize: $input->fileSize,
            mimeType: $input->mimeType,
            chunkSize: $chunkSize,
            totalChunks: $totalChunks,
            disk: $disk,
            profile: $input->profile,
            metadata: $input->metadata,
            uploadedChunks: [],
            status: UploadStatus::Pending,
            finalPath: $finalPath,
            batchId: $input->batchId,
            userId: $userId,
            fingerprint: $fingerprint,
            expiresAt: $this->clock->now()->add(new DateInterval('PT'.$this->config->expirationHours.'H')),
            transport: $transport,
            remoteUploadId: $remoteUploadId,
        );

        $this->uploads->create($record);
        $this->events->dispatch(new UploadInitiated($record));

        return new InitiateResult(
            $uploadId,
            $chunkSize,
            $totalChunks,
            false,
            [],
            $input->batchId,
            $record->isDirect() ? $this->direct->transportPayload($record, range(0, $totalChunks - 1)) : null,
        );
    }

    private function resumeResult(UploadRecord $existing): InitiateResult
    {
        if (! $existing->isDirect()) {
            return new InitiateResult(
                $existing->uploadId,
                $existing->chunkSize,
                $existing->totalChunks,
                true,
                $existing->uploadedChunks,
                $existing->batchId,
            );
        }

        // K2: the remote part list is refreshed only here, at resume-initiate.
        $uploadedParts = $this->direct->uploadedParts($existing);
        $uploaded = array_keys($uploadedParts);
        $missing = array_values(array_diff(range(0, $existing->totalChunks - 1), $uploaded));

        return new InitiateResult(
            $existing->uploadId,
            $existing->chunkSize,
            $existing->totalChunks,
            true,
            $uploaded,
            $existing->batchId,
            $this->direct->transportPayload($existing, $missing, $uploadedParts),
        );
    }

    public function uploadChunk(string $uploadId, int $chunkIndex, UploadedFile $chunk, ?string $fileChecksum = null): ChunkUploadOutcome
    {
        $record = $this->uploads->find($uploadId);

        if ($record === null) {
            throw UploadNotFoundException::forUpload($uploadId);
        }

        if (! $record->status->isTerminal() && $record->isExpiredAt($this->clock->now())) {
            throw UploadExpiredException::forUpload($uploadId);
        }

        // Direct uploads PUT their parts straight to S3 — this endpoint is
        // not part of their lifecycle.
        if ($record->isDirect()) {
            throw InvalidStateException::upload($record->status, UploadStatus::Uploading);
        }

        if (! in_array($record->status, [UploadStatus::Pending, UploadStatus::Uploading], true)) {
            throw InvalidStateException::upload($record->status, UploadStatus::Uploading);
        }

        if ($chunkIndex < 0 || $chunkIndex >= $record->totalChunks) {
            throw ChunkIndexOutOfRangeException::make($chunkIndex, $record->totalChunks);
        }

        if ($record->status === UploadStatus::Pending) {
            $this->uploads->transition($uploadId, UploadStatus::Pending, UploadStatus::Uploading);
        }

        // Accepted on any chunk request; the first non-empty value is kept.
        if ($fileChecksum !== null && $record->fileChecksum === null) {
            $this->uploads->setFileChecksum($uploadId, strtolower($fileChecksum));
        }

        $this->chunks->put($uploadId, $chunkIndex, $chunk);
        $progress = $this->uploads->markChunk($uploadId, $chunkIndex);
        $this->events->dispatch(new ChunkUploaded($uploadId, $chunkIndex, $progress->uploadedCount, $progress->totalChunks));

        if (! $progress->isComplete()) {
            return new ChunkUploadOutcome(
                UploadStatus::Uploading,
                $chunkIndex,
                $progress->uploadedCount,
                $progress->totalChunks,
                $progress->percentage(),
            );
        }

        return $this->finish($uploadId, $chunkIndex, $progress);
    }

    public function find(string $uploadId): ?UploadRecord
    {
        return $this->uploads->find($uploadId);
    }

    public function cancel(string $uploadId): UploadRecord
    {
        $record = $this->uploads->find($uploadId);

        if ($record === null) {
            throw UploadNotFoundException::forUpload($uploadId);
        }

        if ($record->status === UploadStatus::Cancelled) {
            return $record;
        }

        $cancelled = $this->uploads->transition($uploadId, UploadStatus::Pending, UploadStatus::Cancelled)
            || $this->uploads->transition($uploadId, UploadStatus::Uploading, UploadStatus::Cancelled);

        if (! $cancelled) {
            throw InvalidStateException::upload($record->status, UploadStatus::Cancelled);
        }

        $this->chunks->purge($uploadId);
        $this->direct->abortRemote($record);
        $fresh = $this->uploads->find($uploadId) ?? $record;
        $this->events->dispatch(new UploadCancelled($fresh));

        return $fresh;
    }

    public function assemblyMode(int $fileSize): string
    {
        return match ($this->config->assemblyMode) {
            'sync' => 'sync',
            'queue' => 'queue',
            default => $fileSize <= $this->config->assemblySyncThreshold ? 'sync' : 'queue',
        };
    }

    private function finish(string $uploadId, int $chunkIndex, ChunkProgress $progress): ChunkUploadOutcome
    {
        $record = $this->uploads->find($uploadId) ?? throw UploadNotFoundException::forUpload($uploadId);

        if ($this->config->integrityRequireFullFile && $record->fileChecksum === null) {
            throw MissingFileChecksumException::forUpload($uploadId);
        }

        if ($this->assemblyMode($record->fileSize) === 'sync') {
            $result = $this->assembler->run($uploadId);

            return new ChunkUploadOutcome(
                $result->status,
                $chunkIndex,
                $progress->uploadedCount,
                $progress->totalChunks,
                100.0,
                $result,
            );
        }

        AssembleFileJob::dispatch($uploadId);

        return new ChunkUploadOutcome(
            UploadStatus::Assembling,
            $chunkIndex,
            $progress->uploadedCount,
            $progress->totalChunks,
            100.0,
        );
    }

    private function resolveFinalPath(string $uploadId, ?UploadProfile $profile, UploadContext $context): string
    {
        if ($profile !== null) {
            $directory = trim($profile->directory($context), '/');
            $name = $this->safeBasename($profile->fileName($context, $context->fileName));
        } else {
            $directory = 'chunky/uploads/'.$uploadId;
            $name = $this->safeBasename($context->fileName);
        }

        return ($directory !== '' ? $directory.'/' : '').$name;
    }

    private function safeBasename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));

        if ($base === '' || $base === '.' || $base === '..') {
            throw new ChunkyException('The file name is invalid.');
        }

        return $base;
    }
}
