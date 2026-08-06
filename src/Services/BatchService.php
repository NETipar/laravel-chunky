<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services;

use DateInterval;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use NETipar\Chunky\Config\ChunkyConfig;
use NETipar\Chunky\Domain\BatchCounter;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Domain\BatchStatusResult;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Events\BatchCancelled;
use NETipar\Chunky\Events\BatchCompleted;
use NETipar\Chunky\Events\BatchInitiated;
use NETipar\Chunky\Events\BatchPartiallyCompleted;
use NETipar\Chunky\Events\UploadCancelled;
use NETipar\Chunky\Exceptions\BatchNotFoundException;
use NETipar\Chunky\Exceptions\InvalidStateException;
use NETipar\Chunky\Ports\BatchRepository;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Ports\Clock;
use NETipar\Chunky\Ports\UploadRepository;
use NETipar\Chunky\Support\Coerce;

final class BatchService
{
    public function __construct(
        private readonly BatchRepository $batches,
        private readonly UploadRepository $uploads,
        private readonly ChunkStore $chunks,
        private readonly Dispatcher $events,
        private readonly ChunkyConfig $config,
        private readonly Clock $clock,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initiate(int $totalFiles, ?string $profile, array $metadata, ?Authenticatable $user): BatchRecord
    {
        $batchId = (string) Str::uuid();

        $record = new BatchRecord(
            batchId: $batchId,
            totalFiles: $totalFiles,
            status: BatchStatus::Pending,
            profile: $profile,
            metadata: $metadata,
            userId: Coerce::toNullableString($user?->getAuthIdentifier()),
            expiresAt: $this->clock->now()->add(new DateInterval('PT'.$this->config->expirationHours.'H')),
        );

        $this->batches->create($record);
        $this->events->dispatch(new BatchInitiated($batchId, $totalFiles));

        return $record;
    }

    public function find(string $batchId): ?BatchRecord
    {
        return $this->batches->find($batchId);
    }

    public function status(string $batchId): BatchStatusResult
    {
        $batch = $this->batches->find($batchId);

        if ($batch === null) {
            throw BatchNotFoundException::forBatch($batchId);
        }

        $uploads = array_map(
            static fn (UploadRecord $u): array => [
                'upload_id' => $u->uploadId,
                'status' => $u->status->value,
                'progress' => $u->progress(),
            ],
            $this->uploads->findByBatch($batchId),
        );

        return new BatchStatusResult(
            $batch->batchId,
            $batch->status,
            $batch->totalFiles,
            $batch->completedFiles,
            $batch->failedFiles,
            array_values($uploads),
        );
    }

    public function cancel(string $batchId): void
    {
        $batch = $this->batches->find($batchId);

        if ($batch === null) {
            throw BatchNotFoundException::forBatch($batchId);
        }

        if ($batch->status->isTerminal()) {
            throw InvalidStateException::batch($batch->status, BatchStatus::Cancelled);
        }

        // Driver-agnostic: cancel every non-terminal member via findByBatch.
        foreach ($this->uploads->findByBatch($batchId) as $upload) {
            $this->cancelMember($upload);
        }

        $this->batches->transition($batchId, $batch->status, BatchStatus::Cancelled);
        $this->events->dispatch(new BatchCancelled($batchId));
    }

    public function onUploadCompleted(UploadRecord $upload): void
    {
        $this->applyCounter($upload->batchId, BatchCounter::Completed);
    }

    public function onUploadFailed(UploadRecord $upload): void
    {
        $this->applyCounter($upload->batchId, BatchCounter::Failed);
    }

    private function applyCounter(?string $batchId, BatchCounter $counter): void
    {
        if ($batchId === null) {
            return;
        }

        $finalized = false;

        $batch = $this->batches->increment($batchId, $counter, function (BatchRecord $b) use (&$finalized): BatchStatus {
            $finalized = true;

            return $b->resolveFinalStatus();
        });

        if (! $finalized) {
            return;
        }

        // Event dispatch happens here, outside the repository's lock/transaction.
        if ($batch->status === BatchStatus::Completed) {
            $this->events->dispatch(new BatchCompleted($batch->batchId, $batch->totalFiles));
        } elseif ($batch->status === BatchStatus::PartiallyCompleted) {
            $this->events->dispatch(new BatchPartiallyCompleted(
                $batch->batchId,
                $batch->completedFiles,
                $batch->failedFiles,
                $batch->totalFiles,
            ));
        }
    }

    private function cancelMember(UploadRecord $upload): void
    {
        $cancelled = $this->uploads->transition($upload->uploadId, UploadStatus::Pending, UploadStatus::Cancelled)
            || $this->uploads->transition($upload->uploadId, UploadStatus::Uploading, UploadStatus::Cancelled);

        if (! $cancelled) {
            return;
        }

        $this->chunks->purge($upload->uploadId);

        // Resolved lazily: DirectUploadService depends on BatchService, so a
        // constructor dependency here would be circular.
        if ($upload->isDirect()) {
            $this->container->make(DirectUploadService::class)->abortRemote($upload);
        }

        $this->events->dispatch(new UploadCancelled($this->uploads->find($upload->uploadId) ?? $upload));
    }
}
