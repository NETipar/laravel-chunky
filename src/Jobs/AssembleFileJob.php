<?php

declare(strict_types=1);

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
use NETipar\Chunky\Support\Metrics;

class AssembleFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow the queue to retry the assembly if a worker crashed mid-job.
     * The tracker's `claimForAssembly()` is now able to take over a stale
     * claim (status is Assembling but the row hasn't been touched in a
     * while), so a retry actually makes progress instead of hitting the
     * CAS guard.
     *
     * All three (`tries`, `backoff`, `timeout`) are populated from
     * `config('chunky.assembly.*')` in the constructor so production
     * setups can tune them without subclassing the job. `timeout` in
     * particular matters for large uploads — the queue worker default
     * 60s is far too short for a 50GB assembly.
     */
    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 600;

    public function __construct(
        public readonly string $uploadId,
    ) {
        $this->tries = (int) config('chunky.assembly.tries', 3);
        $this->backoff = (int) config('chunky.assembly.backoff', 30);
        $this->timeout = (int) config('chunky.assembly.timeout', 600);

        $queue = config('chunky.assembly.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(ChunkHandler $handler, UploadTracker $tracker, ChunkyManager $manager): void
    {
        $metadata = $tracker->getMetadata($this->uploadId);

        if (! $metadata) {
            return;
        }

        // Skip the work entirely if the upload already reached a terminal
        // state — covers the case where the worker died after updateStatus()
        // but before dispatching follow-up events, and the queue retries us.
        if ($metadata->status->isTerminal()) {
            return;
        }

        if (! $tracker->claimForAssembly($this->uploadId)) {
            return;
        }

        Metrics::emit('assembly_started', [
            'upload_id' => $this->uploadId,
            'file_size' => $metadata->fileSize,
            'total_chunks' => $metadata->totalChunks,
        ]);

        $startedAt = hrtime(true);

        $finalPath = $handler->assemble($metadata);

        Metrics::emit('assembly_completed', [
            'upload_id' => $this->uploadId,
            'file_size' => $metadata->fileSize,
            'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
        ]);

        FileAssembled::dispatch(
            $this->uploadId,
            $finalPath,
            $metadata->disk,
            $metadata->fileName,
            $metadata->fileSize,
        );

        $completedMetadata = $metadata->withStatus(UploadStatus::Completed, $finalPath);

        if ($metadata->context) {
            $saveCallback = $manager->getContextSaveCallback($metadata->context);

            try {
                $saveCallback?->__invoke($completedMetadata);
            } catch (\Throwable $e) {
                $tracker->updateStatus($this->uploadId, UploadStatus::Failed);
                $handler->cleanup($this->uploadId);

                UploadFailed::dispatch($completedMetadata, $e->getMessage());

                if ($completedMetadata->batchId) {
                    $manager->markBatchUploadFailed($completedMetadata->batchId);
                }

                throw $e;
            }
        }

        $tracker->updateStatus($this->uploadId, UploadStatus::Completed, $finalPath);

        // Clean up only after the upload is in a terminal Completed state, so
        // a save-callback failure or worker crash mid-flight doesn't leave us
        // with no chunks AND no completed file — letting a retry recover.
        $handler->cleanup($this->uploadId);

        UploadCompleted::dispatch($completedMetadata);

        if ($completedMetadata->batchId) {
            $manager->markBatchUploadCompleted($completedMetadata->batchId);
        }
    }

    public function failed(\Throwable $e): void
    {
        $tracker = app(UploadTracker::class);
        $metadata = $tracker->getMetadata($this->uploadId);

        // If the upload already settled into ANY terminal state, leave it
        // alone. In particular, refusing to flip Completed → Failed prevents
        // a queue retry of an already-successful handle() (see the in-handle
        // crash window) from confusing the frontend with a UploadFailed
        // event after a UploadCompleted has already gone out.
        if ($metadata !== null && $metadata->status->isTerminal()) {
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

        Metrics::emit('assembly_failed', [
            'upload_id' => $this->uploadId,
            'batch_id' => $metadata?->batchId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
