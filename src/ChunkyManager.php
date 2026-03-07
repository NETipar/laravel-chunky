<?php

namespace NETipar\Chunky;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Events\ChunkUploaded;
use NETipar\Chunky\Events\ChunkUploadFailed;
use NETipar\Chunky\Events\UploadInitiated;
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
     * @return array{upload_id: string, chunk_size: int, total_chunks: int}
     */
    public function initiate(
        string $fileName,
        int $fileSize,
        ?string $mimeType = null,
        array $metadata = [],
        ?string $context = null,
    ): array {
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
        );

        $this->tracker->initiate($uploadId, $uploadMetadata);

        UploadInitiated::dispatch($uploadId, $fileName, $fileSize, $totalChunks);

        return [
            'upload_id' => $uploadId,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
        ];
    }

    /**
     * @return array{is_complete: bool, metadata: UploadMetadata}
     */
    public function uploadChunk(string $uploadId, int $chunkIndex, UploadedFile $chunk): array
    {
        try {
            $this->handler->store($uploadId, $chunkIndex, $chunk);
            $this->tracker->markChunkUploaded($uploadId, $chunkIndex);

            $metadata = $this->tracker->getMetadata($uploadId);
            $totalChunks = $metadata?->totalChunks ?? 0;

            ChunkUploaded::dispatch($uploadId, $chunkIndex, $totalChunks);

            return [
                'is_complete' => $this->tracker->isComplete($uploadId),
                'metadata' => $metadata,
            ];
        } catch (\Throwable $e) {
            ChunkUploadFailed::dispatch($uploadId, $chunkIndex, $e);
            throw $e;
        }
    }

    public function status(string $uploadId): ?UploadMetadata
    {
        return $this->tracker->getMetadata($uploadId);
    }

    public function handler(): ChunkHandler
    {
        return $this->handler;
    }

    public function tracker(): UploadTracker
    {
        return $this->tracker;
    }
}
