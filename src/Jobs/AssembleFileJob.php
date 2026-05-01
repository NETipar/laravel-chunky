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
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Events\FileAssembled;
use NETipar\Chunky\Events\UploadCompleted;
use NETipar\Chunky\Events\UploadFailed;

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

        if (! $tracker->claimForAssembly($this->uploadId)) {
            return;
        }

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

        $completedMetadata = $metadata->withStatus(UploadStatus::Completed, $finalPath);

        if ($metadata->context) {
            $saveCallback = $manager->getContextSaveCallback($metadata->context);

            try {
                $saveCallback?->__invoke($completedMetadata);
            } catch (\Throwable $e) {
                $tracker->updateStatus($this->uploadId, UploadStatus::Failed);

                UploadFailed::dispatch($completedMetadata, $e->getMessage());

                if ($completedMetadata->batchId) {
                    $manager->markBatchUploadFailed($completedMetadata->batchId);
                }

                throw $e;
            }
        }

        $tracker->updateStatus($this->uploadId, UploadStatus::Completed, $finalPath);

        UploadCompleted::dispatch($completedMetadata);

        if ($completedMetadata->batchId) {
            $manager->markBatchUploadCompleted($completedMetadata->batchId);
        }
    }

    public function failed(\Throwable $e): void
    {
        $tracker = app(UploadTracker::class);
        $metadata = $tracker->getMetadata($this->uploadId);

        if ($metadata?->status === UploadStatus::Failed) {
            return;
        }

        $tracker->updateStatus($this->uploadId, UploadStatus::Failed);

        if ($metadata) {
            UploadFailed::dispatch($metadata->withStatus(UploadStatus::Failed), $e->getMessage());
        }

        if ($metadata?->batchId) {
            $manager = app(ChunkyManager::class);
            $manager->markBatchUploadFailed($metadata->batchId);
        }
    }
}
