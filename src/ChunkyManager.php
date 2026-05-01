<?php

namespace NETipar\Chunky;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Data\ChunkUploadResult;
use NETipar\Chunky\Data\InitiateResult;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\BatchStatus;
use NETipar\Chunky\Events\BatchCompleted;
use NETipar\Chunky\Events\BatchInitiated;
use NETipar\Chunky\Events\BatchPartiallyCompleted;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\ChunkUploadFailed;
use NETipar\Chunky\Events\UploadInitiated;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Models\ChunkyBatch;
use NETipar\Chunky\Support\ChunkCalculator;

class ChunkyManager
{
    /** @var array<string, array{rules: ?\Closure, save: ?\Closure}> */
    private array $contexts = [];

    public function __construct(
        private ChunkHandler $handler,
        private UploadTracker $tracker,
    ) {}

    /**
     * Register a class-based upload context.
     *
     * @param  class-string<ChunkyContext>  $contextClass
     */
    public function register(string $contextClass): void
    {
        $instance = app($contextClass);

        $this->context(
            name: $instance->name(),
            rules: fn () => $instance->rules(),
            save: fn (UploadMetadata $metadata) => $instance->save($metadata),
        );
    }

    /**
     * Quick context registration: validates and moves the file to the given directory.
     *
     * @param  array{max_size?: int, mimes?: array<int, string>}  $options
     */
    public function simple(string $name, string $directory, array $options = []): void
    {
        $rules = null;

        if (! empty($options['max_size']) || ! empty($options['mimes'])) {
            $rules = function () use ($options) {
                $r = [];

                if (! empty($options['max_size'])) {
                    $r['file_size'] = ["max:{$options['max_size']}"];
                }

                if (! empty($options['mimes'])) {
                    $r['mime_type'] = ['in:'.implode(',', $options['mimes'])];
                }

                return $r;
            };
        }

        $this->context(
            name: $name,
            rules: $rules,
            save: function (UploadMetadata $metadata) use ($directory) {
                $disk = Storage::disk($metadata->disk);
                $destination = rtrim($directory, '/')."/{$metadata->fileName}";

                $disk->move($metadata->finalPath, $destination);
            },
        );
    }

    /**
     * Register validation rules and/or save handler for an upload context.
     *
     * @param  ?\Closure(): array<string, array<int, mixed>>  $rules
     * @param  ?\Closure(UploadMetadata $metadata): void  $save
     */
    public function context(string $name, ?\Closure $rules = null, ?\Closure $save = null): void
    {
        $this->contexts[$name] = [
            'rules' => $rules,
            'save' => $save,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function getContextRules(string $name): array
    {
        if (! isset($this->contexts[$name]['rules'])) {
            return [];
        }

        return ($this->contexts[$name]['rules'])();
    }

    public function getContextSaveCallback(string $name): ?\Closure
    {
        return $this->contexts[$name]['save'] ?? null;
    }

    public function hasContext(string $name): bool
    {
        return isset($this->contexts[$name]);
    }

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
        try {
            $this->handler->store($uploadId, $chunkIndex, $chunk);
            $metadata = $this->tracker->markChunkUploaded($uploadId, $chunkIndex);

            ChunkUploaded::dispatch($uploadId, $chunkIndex, $metadata->totalChunks);

            return new ChunkUploadResult(
                isComplete: count($metadata->uploadedChunks) >= $metadata->totalChunks,
                metadata: $metadata,
            );
        } catch (\Throwable $e) {
            ChunkUploadFailed::dispatch($uploadId, $chunkIndex, $e);
            throw $e;
        }
    }

    public function status(string $uploadId): ?UploadMetadata
    {
        return $this->tracker->getMetadata($uploadId);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initiateBatch(int $totalFiles, ?string $context = null, array $metadata = []): BatchMetadata
    {
        $batchId = (string) Str::uuid();
        $userId = $this->resolveUserId();

        if (config('chunky.tracker') === 'database') {
            ChunkyBatch::create([
                'batch_id' => $batchId,
                'user_id' => $userId,
                'total_files' => $totalFiles,
                'context' => $context,
                'metadata' => $metadata ?: null,
                'status' => BatchStatus::Pending,
                'expires_at' => now()->addMinutes(config('chunky.expiration', 1440)),
            ]);
        } else {
            $this->writeBatchJson($batchId, [
                'batch_id' => $batchId,
                'user_id' => $userId,
                'total_files' => $totalFiles,
                'completed_files' => 0,
                'failed_files' => 0,
                'context' => $context,
                'metadata' => $metadata ?: null,
                'status' => BatchStatus::Pending->value,
                'expires_at' => now()->addMinutes(config('chunky.expiration', 1440))->toIso8601String(),
                'created_at' => now()->toIso8601String(),
            ]);
        }

        BatchInitiated::dispatch($batchId, $totalFiles);

        return new BatchMetadata(
            batchId: $batchId,
            totalFiles: $totalFiles,
            completedFiles: 0,
            failedFiles: 0,
            status: BatchStatus::Pending,
            context: $context,
            userId: $userId,
        );
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
        $this->validateBatchExists($batchId);

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

        if (config('chunky.tracker') === 'database') {
            ChunkyBatch::where('batch_id', $batchId)
                ->where('status', BatchStatus::Pending)
                ->update(['status' => BatchStatus::Processing]);
        } else {
            $batchData = $this->readBatchJson($batchId);

            if ($batchData && $batchData['status'] === BatchStatus::Pending->value) {
                $batchData['status'] = BatchStatus::Processing->value;
                $this->writeBatchJson($batchId, $batchData);
            }
        }

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
        if (config('chunky.tracker') === 'database') {
            $batch = ChunkyBatch::where('batch_id', $batchId)->first();

            if (! $batch) {
                return null;
            }

            return new BatchMetadata(
                batchId: $batch->batch_id,
                totalFiles: $batch->total_files,
                completedFiles: $batch->completed_files,
                failedFiles: $batch->failed_files,
                status: $batch->status,
                context: $batch->context,
                userId: $batch->user_id,
            );
        }

        $batchData = $this->readBatchJson($batchId);

        if (! $batchData) {
            return null;
        }

        return BatchMetadata::fromArray($batchData);
    }

    public function markBatchUploadCompleted(string $batchId): void
    {
        if (config('chunky.tracker') === 'database') {
            $batch = ChunkyBatch::where('batch_id', $batchId)->first();
            $batch?->markUploadCompleted();

            return;
        }

        $this->updateFilesystemBatchCounter($batchId, 'completed_files');
    }

    public function markBatchUploadFailed(string $batchId): void
    {
        if (config('chunky.tracker') === 'database') {
            $batch = ChunkyBatch::where('batch_id', $batchId)->first();
            $batch?->markUploadFailed();

            return;
        }

        $this->updateFilesystemBatchCounter($batchId, 'failed_files');
    }

    public function handler(): ChunkHandler
    {
        return $this->handler;
    }

    public function tracker(): UploadTracker
    {
        return $this->tracker;
    }

    private function updateFilesystemBatchCounter(string $batchId, string $field): void
    {
        $data = $this->readBatchJson($batchId);

        if (! $data) {
            return;
        }

        $data[$field] = ($data[$field] ?? 0) + 1;

        $completed = $data['completed_files'] ?? 0;
        $failed = $data['failed_files'] ?? 0;
        $total = $data['total_files'] ?? 0;

        if ($completed + $failed >= $total) {
            $status = $failed > 0
                ? BatchStatus::PartiallyCompleted
                : BatchStatus::Completed;

            $data['status'] = $status->value;
            $data['completed_at'] = now()->toIso8601String();
            $this->writeBatchJson($batchId, $data);

            $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;

            match ($status) {
                BatchStatus::Completed => BatchCompleted::dispatch($batchId, $total, $userId),
                BatchStatus::PartiallyCompleted => BatchPartiallyCompleted::dispatch($batchId, $completed, $failed, $total, $userId),
                default => null,
            };

            return;
        }

        $this->writeBatchJson($batchId, $data);
    }

    private function resolveUserId(): ?int
    {
        if (! function_exists('auth')) {
            return null;
        }

        $id = auth()->id();

        return $id !== null ? (int) $id : null;
    }

    private function validateBatchExists(string $batchId): void
    {
        if (config('chunky.tracker') === 'database') {
            $batch = ChunkyBatch::where('batch_id', $batchId)->first();

            if (! $batch) {
                throw new ChunkyException("Batch {$batchId} not found.");
            }

            if ($batch->isExpired()) {
                $batch->update(['status' => BatchStatus::Expired]);
                throw new ChunkyException("Batch {$batchId} has expired.");
            }

            return;
        }

        $batchData = $this->readBatchJson($batchId);

        if (! $batchData) {
            throw new ChunkyException("Batch {$batchId} not found.");
        }

        if (isset($batchData['expires_at']) && now()->isAfter($batchData['expires_at'])) {
            $batchData['status'] = BatchStatus::Expired->value;
            $this->writeBatchJson($batchId, $batchData);
            throw new ChunkyException("Batch {$batchId} has expired.");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readBatchJson(string $batchId): ?array
    {
        $path = config('chunky.temp_directory')."/batches/{$batchId}/batch.json";
        $disk = Storage::disk(config('chunky.disk'));

        if (! $disk->exists($path)) {
            return null;
        }

        return json_decode($disk->get($path), true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeBatchJson(string $batchId, array $data): void
    {
        $path = config('chunky.temp_directory')."/batches/{$batchId}/batch.json";

        Storage::disk(config('chunky.disk'))->put($path, json_encode($data));
    }
}
