<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services;

use DateInterval;
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
use NETipar\Chunky\Exceptions\UploadNotFoundException;
use NETipar\Chunky\Ports\Clock;
use NETipar\Chunky\Ports\UploadRepository;
use NETipar\Chunky\Profiles\ProfileRegistry;
use NETipar\Chunky\Services\Assembly\AssemblyPipelineFactory;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use Throwable;

/**
 * Claims an upload for assembly, runs the pipeline, and settles the terminal
 * state + events. Shared by the sync path (in-request) and the queued job.
 */
final class AssemblyRunner
{
    public function __construct(
        private readonly UploadRepository $uploads,
        private readonly AssemblyPipelineFactory $pipelines,
        private readonly ProfileRegistry $profiles,
        private readonly ChunkyConfig $config,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly FilesystemFactory $filesystem,
        private readonly BatchService $batches,
    ) {}

    public function run(string $uploadId): UploadResult
    {
        $record = $this->uploads->find($uploadId);

        if ($record === null) {
            throw UploadNotFoundException::forUpload($uploadId);
        }

        if (! $this->claim($record)) {
            // Another worker owns the claim, or the upload is already terminal.
            return $this->resultFor($this->uploads->find($uploadId) ?? $record);
        }

        $claimed = $this->uploads->find($uploadId) ?? $record;
        $state = new AssemblyState($claimed, $this->profiles->resolve($claimed->profile), $claimed->disk);

        try {
            $this->pipelines->forDisk($claimed->disk)->run($state);
        } catch (Throwable $e) {
            $this->fail($claimed, $e->getMessage());

            throw AssemblyFailedException::forUpload($uploadId, $e);
        }

        $this->uploads->transition($uploadId, UploadStatus::Assembling, UploadStatus::Completed, [
            'final_path' => $state->finalPath,
            'result_payload' => $state->payload,
        ]);

        $completed = $this->uploads->find($uploadId) ?? $claimed;
        $this->events->dispatch(new FileAssembled($completed));
        $this->events->dispatch(new UploadCompleted($completed));
        $this->batches->onUploadCompleted($completed);

        return $this->resultFor($completed);
    }

    /**
     * Terminal failure handler for the queue's failed() callback: only acts if
     * the upload is still Assembling (i.e. the claim was ours and never settled).
     */
    public function markFailed(string $uploadId, string $reason): void
    {
        $record = $this->uploads->find($uploadId);

        if ($record !== null && $record->status === UploadStatus::Assembling) {
            $this->fail($record, $reason);
        }
    }

    private function claim(UploadRecord $record): bool
    {
        $now = $this->clock->now();

        if ($this->uploads->transition($record->uploadId, UploadStatus::Uploading, UploadStatus::Assembling, ['claimed_at' => $now])) {
            return true;
        }

        $threshold = $now->sub(new DateInterval('PT'.$this->config->assemblyStaleClaimSeconds.'S'));

        return $this->uploads->transition(
            $record->uploadId,
            UploadStatus::Assembling,
            UploadStatus::Assembling,
            ['claimed_at' => $now],
            ['claimed_before' => $threshold],
        );
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
            $file = new CompletedFile(
                $record->finalPath,
                $record->fileSize,
                $this->publicUrl($record->disk, $record->finalPath),
            );
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
