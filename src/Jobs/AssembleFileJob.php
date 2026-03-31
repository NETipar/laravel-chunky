<?php

namespace NETipar\Chunky\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use NETipar\Chunky\ChunkyManager;
use NETipar\Chunky\Contracts\ChunkHandler;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Events\FileAssembled;
use NETipar\Chunky\Events\UploadCompleted;
class AssembleFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $uploadId,
    ) {}

    public function handle(ChunkHandler $handler, UploadTracker $tracker, ChunkyManager $manager): void
    {
        $metadata = $tracker->getMetadata($this->uploadId);

        if (! $metadata) {
            return;
        }

        $tracker->updateStatus($this->uploadId, UploadStatus::Assembling);

        $finalPath = $handler->assemble(
            $this->uploadId,
            $metadata->fileName,
            $metadata->totalChunks,
        );

        FileAssembled::dispatch(
            $this->uploadId,
            $finalPath,
            $metadata->disk,
            $metadata->fileName,
            $metadata->fileSize,
        );

        $handler->cleanup($this->uploadId);

        $tracker->updateStatus($this->uploadId, UploadStatus::Completed, $finalPath);

        $completedMetadata = new UploadMetadata(
            uploadId: $metadata->uploadId,
            fileName: $metadata->fileName,
            fileSize: $metadata->fileSize,
            mimeType: $metadata->mimeType,
            chunkSize: $metadata->chunkSize,
            totalChunks: $metadata->totalChunks,
            disk: $metadata->disk,
            context: $metadata->context,
            metadata: $metadata->metadata,
            uploadedChunks: $metadata->uploadedChunks,
            status: UploadStatus::Completed,
            finalPath: $finalPath,
        );

        if ($metadata->context) {
            $saveCallback = $manager->getContextSaveCallback($metadata->context);
            $saveCallback?->__invoke($completedMetadata);
        }

        UploadCompleted::dispatch(
            $this->uploadId,
            $finalPath,
            $metadata->disk,
            $metadata->metadata,
        );
    }
}
