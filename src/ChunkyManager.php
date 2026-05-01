<?php

declare(strict_types=1);

namespace NETipar\Chunky;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use NETipar\Chunky\Contracts\BatchTracker;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Data\ChunkUploadResult;
use NETipar\Chunky\Data\InitiateResult;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\BatchStatus;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Events\BatchCancelled;
use NETipar\Chunky\Events\BatchInitiated;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\ChunkUploadFailed;
use NETipar\Chunky\Events\UploadCancelled;
use NETipar\Chunky\Events\UploadInitiated;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Models\ChunkedUpload;
use NETipar\Chunky\Support\ChunkCalculator;
use NETipar\Chunky\Support\ContextRegistry;
use NETipar\Chunky\Support\Metrics;
use NETipar\Chunky\Trackers\DatabaseTracker;

/**
 * The package's coordinating service. After the v0.18 refactor it is a
 * thin orchestrator: per-upload state lives in UploadTracker, per-batch
 * state lives in BatchTracker, and validation/save callbacks live in
 * ContextRegistry. The manager wires them together and emits the
 * lifecycle events that the package promises.
 */
class ChunkyManager
{
    public function __construct(
        private ChunkHandler $handler,
        private UploadTracker $tracker,
        private BatchTracker $batchTracker,
        private ContextRegistry $contexts,
    ) {}

    // ------------------------------------------------------------------
    // Context registration (delegates to ContextRegistry — kept on the
    // manager surface for back-compat with existing callers).
    // ------------------------------------------------------------------

    /**
     * Register a class-based upload context.
     *
     * @param  class-string<ChunkyContext>  $contextClass
     */
    public function register(string $contextClass): void
    {
        $this->contexts->registerClass($contextClass);
    }

    /**
     * Quick context registration: validates and moves the file to the given directory.
     *
     * @param  array{max_size?: int, mimes?: array<int, string>}  $options
     */
    public function simple(string $name, string $directory, array $options = []): void
    {
        $this->contexts->registerSimple($name, $directory, $options);
    }

    /**
     * Register validation rules and/or save handler for an upload context.
     *
     * @param  ?Closure(): array<string, array<int, mixed>>  $rules
     * @param  ?Closure(UploadMetadata $metadata): void  $save
     */
    public function context(string $name, ?Closure $rules = null, ?Closure $save = null): void
    {
        $this->contexts->register($name, $rules, $save);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function getContextRules(string $name): array
    {
        return $this->contexts->rules($name);
    }

    public function getContextSaveCallback(string $name): ?Closure
    {
        return $this->contexts->saveCallback($name);
    }

    public function hasContext(string $name): bool
    {
        return $this->contexts->has($name);
    }

    public function contexts(): ContextRegistry
    {
        return $this->contexts;
    }

    // ------------------------------------------------------------------
    // Single-file upload lifecycle.
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initiate(
        string $fileName,
        int $fileSize,
        ?string $mimeType = null,
        array $metadata = [],
        ?string $context = null,
    ): InitiateResult {
        $uploadId = (string) Str::uuid();
        $chunkSize = ChunkCalculator::chunkSize();
        $totalChunks = ChunkCalculator::totalChunks($fileSize, $chunkSize);

        $uploadMetadata = new UploadMetadata(
            uploadId: $uploadId,
            fileName: $fileName,
            fileSize: $fileSize,
            mimeType: $mimeType,
            chunkSize: $chunkSize,
            totalChunks: $totalChunks,
            disk: config('chunky.disk'),
            context: $context,
            metadata: $metadata,
            userId: $this->resolveUserId(),
        );

        $this->tracker->initiate($uploadId, $uploadMetadata);

        UploadInitiated::dispatch($uploadId, $fileName, $fileSize, $totalChunks);

        return new InitiateResult(
            uploadId: $uploadId,
            chunkSize: $chunkSize,
            totalChunks: $totalChunks,
        );
    }

    public function uploadChunk(string $uploadId, int $chunkIndex, UploadedFile $chunk): ChunkUploadResult
    {
        // Pre-flight status check before we write the chunk to disk. The
        // tracker's markChunkUploaded() also enforces this under a lock
        // (defence-in-depth against races); doing the check here as well
        // means we don't create orphan chunk files when a late POST hits
        // a cancelled/completed upload.
        $this->assertCanAcceptChunk($uploadId);

        $startedAt = hrtime(true);

        try {
            $this->handler->store($uploadId, $chunkIndex, $chunk);
            $metadata = $this->tracker->markChunkUploaded($uploadId, $chunkIndex);

            ChunkUploaded::dispatch($uploadId, $chunkIndex, $metadata->totalChunks);

            Metrics::emit('chunk_uploaded', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
                'size_bytes' => $chunk->getSize() ?: 0,
            ]);

            return new ChunkUploadResult(
                isComplete: count($metadata->uploadedChunks) >= $metadata->totalChunks,
                metadata: $metadata,
            );
        } catch (\Throwable $e) {
            ChunkUploadFailed::dispatch($uploadId, $chunkIndex, $e);

            Metrics::emit('chunk_upload_failed', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function assertCanAcceptChunk(string $uploadId): void
    {
        $metadata = $this->tracker->getMetadata($uploadId);

        if (! $metadata) {
            throw new ChunkyException("Upload {$uploadId} not found.");
        }

        if ($metadata->status !== UploadStatus::Pending) {
            throw new ChunkyException(
                "Upload {$uploadId} is no longer accepting chunks (status: {$metadata->status->value}).",
            );
        }
    }

    public function status(string $uploadId): ?UploadMetadata
    {
        return $this->tracker->getMetadata($uploadId);
    }

    /**
     * Cancel an in-progress upload: mark it as Cancelled and remove the temp
     * chunks. Returns true if an upload was found and cancelled, false if it
     * never existed or was already completed/cancelled. Dispatches the
     * `UploadCancelled` event so subscribers can react (clear progress
     * UI, decrement parent batch counters, etc.).
     */
    public function cancel(string $uploadId): bool
    {
        $metadata = $this->tracker->getMetadata($uploadId);

        if (! $metadata) {
            return false;
        }

        if (in_array($metadata->status, [UploadStatus::Completed, UploadStatus::Cancelled], true)) {
            return false;
        }

        $this->tracker->updateStatus($uploadId, UploadStatus::Cancelled);
        $this->handler->cleanup($uploadId);

        UploadCancelled::dispatch($uploadId, $metadata->batchId, $metadata->userId);

        return true;
    }

    // ------------------------------------------------------------------
    // Batch lifecycle (delegates to BatchTracker).
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initiateBatch(int $totalFiles, ?string $context = null, array $metadata = []): BatchMetadata
    {
        $batchId = (string) Str::uuid();
        $userId = $this->resolveUserId();

        $batch = new BatchMetadata(
            batchId: $batchId,
            totalFiles: $totalFiles,
            completedFiles: 0,
            failedFiles: 0,
            status: BatchStatus::Pending,
            context: $context,
            userId: $userId,
            metadata: $metadata ?: null,
            expiresAt: now()->addMinutes(config('chunky.lifecycle.expiration_minutes', 360)),
        );

        $this->batchTracker->initiate($batch);

        BatchInitiated::dispatch($batchId, $totalFiles);

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initiateInBatch(
        string $batchId,
        string $fileName,
        int $fileSize,
        ?string $mimeType = null,
        array $metadata = [],
        ?string $context = null,
    ): InitiateResult {
        $this->assertBatchAcceptsUploads($batchId);

        $uploadId = (string) Str::uuid();
        $chunkSize = ChunkCalculator::chunkSize();
        $totalChunks = ChunkCalculator::totalChunks($fileSize, $chunkSize);

        $uploadMetadata = new UploadMetadata(
            uploadId: $uploadId,
            fileName: $fileName,
            fileSize: $fileSize,
            mimeType: $mimeType,
            chunkSize: $chunkSize,
            totalChunks: $totalChunks,
            disk: config('chunky.disk'),
            context: $context,
            metadata: $metadata,
            batchId: $batchId,
            userId: $this->resolveUserId(),
        );

        $this->tracker->initiate($uploadId, $uploadMetadata);

        $this->batchTracker->markProcessing($batchId);

        UploadInitiated::dispatch($uploadId, $fileName, $fileSize, $totalChunks);

        return new InitiateResult(
            uploadId: $uploadId,
            chunkSize: $chunkSize,
            totalChunks: $totalChunks,
            batchId: $batchId,
        );
    }

    public function getBatchStatus(string $batchId): ?BatchMetadata
    {
        return $this->batchTracker->find($batchId);
    }

    public function markBatchUploadCompleted(string $batchId): void
    {
        $this->batchTracker->incrementCompleted($batchId);
    }

    public function markBatchUploadFailed(string $batchId): void
    {
        $this->batchTracker->incrementFailed($batchId);
    }

    /**
     * Cancel a batch and every still-active upload inside it.
     *
     * Returns true when the batch transitioned to Cancelled, false when
     * it was missing or already in a terminal state. Each individual
     * upload is also cancelled (which fires `UploadCancelled` per file)
     * before `BatchCancelled` is dispatched.
     */
    public function cancelBatch(string $batchId): bool
    {
        $batch = $this->batchTracker->find($batchId);

        if (! $batch) {
            return false;
        }

        if ($batch->status->isTerminal()) {
            return false;
        }

        // Cancel each in-flight per-file upload first. The tracker
        // exposes no "find by batch_id" because the contract is
        // intentionally backend-agnostic; for the database driver we
        // could be smarter, but the cancel command iterates batch
        // members through the events pipeline anyway, so the cost is
        // small in practice. Errors here are swallowed — the batch
        // should still flip to Cancelled even if a member upload was
        // already in a terminal state.
        $this->cancelBatchUploads($batch);

        if (! $this->batchTracker->markCancelled($batchId)) {
            return false;
        }

        BatchCancelled::dispatch($batchId, $batch->userId);

        return true;
    }

    private function cancelBatchUploads(BatchMetadata $batch): void
    {
        // Database driver: enumerate the rows attached to this batch and
        // cancel each. Filesystem driver: there is no efficient way to
        // walk batch members without scanning every upload directory,
        // so we skip the per-member cancel step — abandoned chunks fall
        // through to the cleanup sweep.
        if (! $this->tracker instanceof DatabaseTracker) {
            return;
        }

        ChunkedUpload::where('batch_id', $batch->batchId)
            ->whereNotIn('status', [
                UploadStatus::Completed,
                UploadStatus::Cancelled,
                UploadStatus::Failed,
                UploadStatus::Expired,
            ])
            ->pluck('upload_id')
            ->each(fn (string $uploadId) => $this->cancel($uploadId));
    }

    private function assertBatchAcceptsUploads(string $batchId): void
    {
        $batch = $this->batchTracker->find($batchId);

        if (! $batch) {
            throw new ChunkyException("Batch {$batchId} not found.");
        }

        if ($batch->isExpired()) {
            $this->batchTracker->updateStatus($batchId, BatchStatus::Expired);
            throw new ChunkyException("Batch {$batchId} has expired.");
        }

        if ($batch->status->isTerminal()) {
            throw new ChunkyException(
                "Batch {$batchId} is no longer accepting uploads (status: {$batch->status->value}).",
            );
        }
    }

    // ------------------------------------------------------------------
    // Internals.
    // ------------------------------------------------------------------

    /**
     * @internal Used by the package's own internals (cleanup command, jobs).
     *           Not part of the public API — access ChunkHandler/UploadTracker
     *           via the service container or DI instead.
     */
    public function handler(): ChunkHandler
    {
        return $this->handler;
    }

    /**
     * @internal See handler() above.
     */
    public function tracker(): UploadTracker
    {
        return $this->tracker;
    }

    /**
     * @internal
     */
    public function batchTracker(): BatchTracker
    {
        return $this->batchTracker;
    }

    private function resolveUserId(): ?string
    {
        if (! function_exists('auth')) {
            return null;
        }

        /** @var int|string|null $id */
        $id = auth()->id();

        return $id !== null ? (string) $id : null;
    }
}
