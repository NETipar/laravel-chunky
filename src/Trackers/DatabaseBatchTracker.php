<?php

declare(strict_types=1);

namespace NETipar\Chunky\Trackers;

use Illuminate\Support\Facades\DB;
use NETipar\Chunky\Contracts\BatchTracker;
use NETipar\Chunky\Data\BatchMetadata;
use NETipar\Chunky\Enums\BatchStatus;
use NETipar\Chunky\Events\BatchCompleted;
use NETipar\Chunky\Events\BatchPartiallyCompleted;
use NETipar\Chunky\Models\ChunkyBatch;

/**
 * Eloquent-backed batch tracker. The increment helpers run inside a
 * database transaction with row-level locking (SELECT ... FOR UPDATE) so
 * concurrent AssembleFileJob workers cannot lose updates or both flip the
 * status to a terminal value.
 */
class DatabaseBatchTracker implements BatchTracker
{
    public function initiate(BatchMetadata $batch): void
    {
        ChunkyBatch::create([
            'batch_id' => $batch->batchId,
            'user_id' => $batch->userId,
            'total_files' => $batch->totalFiles,
            'completed_files' => $batch->completedFiles,
            'failed_files' => $batch->failedFiles,
            'context' => $batch->context,
            'metadata' => $batch->metadata,
            'status' => $batch->status,
            'expires_at' => $batch->expiresAt ?? now()->addMinutes(config('chunky.lifecycle.expiration_minutes', 360)),
        ]);
    }

    public function find(string $batchId): ?BatchMetadata
    {
        $row = ChunkyBatch::where('batch_id', $batchId)->first();

        return $row ? $this->toMetadata($row) : null;
    }

    public function incrementCompleted(string $batchId): ?BatchMetadata
    {
        return $this->incrementCounter($batchId, 'completed_files');
    }

    public function incrementFailed(string $batchId): ?BatchMetadata
    {
        return $this->incrementCounter($batchId, 'failed_files');
    }

    public function markProcessing(string $batchId): void
    {
        ChunkyBatch::where('batch_id', $batchId)
            ->where('status', BatchStatus::Pending)
            ->update(['status' => BatchStatus::Processing]);
    }

    public function markCancelled(string $batchId): bool
    {
        $updated = ChunkyBatch::where('batch_id', $batchId)
            ->whereNotIn('status', BatchStatus::terminalCases())
            ->update(['status' => BatchStatus::Cancelled, 'completed_at' => now()]);

        return $updated > 0;
    }

    public function updateStatus(string $batchId, BatchStatus $status): void
    {
        ChunkyBatch::where('batch_id', $batchId)->update(['status' => $status]);
    }

    /**
     * @return array<int, string>
     */
    public function expiredBatchIds(): array
    {
        return ChunkyBatch::where('expires_at', '<', now())
            ->whereNotIn('status', BatchStatus::terminalCases())
            ->pluck('batch_id')
            ->all();
    }

    public function forget(string $batchId): void
    {
        ChunkyBatch::where('batch_id', $batchId)->delete();
    }

    /**
     * The single source of truth for batch counter atomicity. Wraps the
     * increment + finalisation check in a transaction with row-level
     * locking so concurrent AssembleFileJob workers cannot:
     * - lose an increment (read-modify-write race)
     * - both decide they finished the batch (terminal-status race)
     * - both dispatch a duplicate BatchCompleted broadcast
     */
    private function incrementCounter(string $batchId, string $column): ?BatchMetadata
    {
        $shouldDispatch = null;
        $dispatchPayload = null;

        $metadata = DB::transaction(function () use ($batchId, $column, &$shouldDispatch, &$dispatchPayload) {
            $batch = ChunkyBatch::where('batch_id', $batchId)->lockForUpdate()->first();

            if (! $batch) {
                return null;
            }

            $alreadyTerminal = $batch->status->isTerminal();

            $batch->{$column}++;

            $finished = $batch->completed_files + $batch->failed_files >= $batch->total_files;

            if ($finished && ! $alreadyTerminal) {
                $batch->status = $batch->failed_files > 0
                    ? BatchStatus::PartiallyCompleted
                    : BatchStatus::Completed;
                $batch->completed_at = now();

                $shouldDispatch = $batch->status;
                $dispatchPayload = [
                    'completed' => $batch->completed_files,
                    'failed' => $batch->failed_files,
                    'total' => $batch->total_files,
                    'userId' => $batch->user_id !== null ? (string) $batch->user_id : null,
                ];
            } elseif (! $finished && $batch->status === BatchStatus::Pending) {
                $batch->status = BatchStatus::Processing;
            }

            $batch->save();

            return $this->toMetadata($batch);
        });

        // Dispatch outside the transaction so the broadcast can't roll
        // back with the DB write — once the row is committed, the event
        // is committed too.
        if ($shouldDispatch === BatchStatus::Completed) {
            BatchCompleted::dispatch($batchId, $dispatchPayload['total'], $dispatchPayload['userId']);
        } elseif ($shouldDispatch === BatchStatus::PartiallyCompleted) {
            BatchPartiallyCompleted::dispatch(
                $batchId,
                $dispatchPayload['completed'],
                $dispatchPayload['failed'],
                $dispatchPayload['total'],
                $dispatchPayload['userId'],
            );
        }

        return $metadata;
    }

    private function toMetadata(ChunkyBatch $row): BatchMetadata
    {
        return new BatchMetadata(
            batchId: $row->batch_id,
            totalFiles: (int) $row->total_files,
            completedFiles: (int) $row->completed_files,
            failedFiles: (int) $row->failed_files,
            status: $row->status,
            context: $row->context,
            userId: $row->user_id !== null ? (string) $row->user_id : null,
            metadata: is_array($row->metadata) ? $row->metadata : null,
            expiresAt: $row->expires_at,
        );
    }
}
