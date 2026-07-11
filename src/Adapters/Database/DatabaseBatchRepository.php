<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use NETipar\Chunky\Domain\BatchCounter;
use NETipar\Chunky\Domain\BatchRecord;
use NETipar\Chunky\Domain\BatchStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Models\ChunkyBatch;
use NETipar\Chunky\Ports\BatchRepository;

final class DatabaseBatchRepository implements BatchRepository
{
    public function create(BatchRecord $record): void
    {
        ChunkyBatch::query()->create([
            'batch_id' => $record->batchId,
            'total_files' => $record->totalFiles,
            'completed_files' => $record->completedFiles,
            'failed_files' => $record->failedFiles,
            'status' => $record->status,
            'profile' => $record->profile,
            'metadata' => $record->metadata,
            'user_id' => $record->userId,
            'expires_at' => $record->expiresAt,
        ]);
    }

    public function find(string $batchId): ?BatchRecord
    {
        $model = ChunkyBatch::query()->find($batchId);

        return $model === null ? null : $this->toRecord($model);
    }

    public function increment(string $batchId, BatchCounter $counter, Closure $onFinalize): BatchRecord
    {
        return DB::transaction(function () use ($batchId, $counter, $onFinalize): BatchRecord {
            $model = ChunkyBatch::query()
                ->where('batch_id', $batchId)
                ->lockForUpdate()
                ->first();

            if ($model === null) {
                throw new ChunkyException("Batch '{$batchId}' not found.");
            }

            if ($counter === BatchCounter::Completed) {
                $model->completed_files++;
            } else {
                $model->failed_files++;
            }

            if ($model->status === BatchStatus::Pending) {
                $model->status = BatchStatus::InProgress;
            }

            $record = $this->toRecord($model);

            if ($record->isFinished() && ! $model->status->isTerminal()) {
                $finalStatus = $onFinalize($record);
                $model->status = $finalStatus;
                $record = $record->with(status: $finalStatus);
            }

            $model->save();

            return $record;
        });
    }

    public function transition(string $batchId, BatchStatus $from, BatchStatus $to): bool
    {
        return ChunkyBatch::query()
            ->where('batch_id', $batchId)
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'updated_at' => now()]) > 0;
    }

    public function delete(string $batchId): void
    {
        ChunkyBatch::query()->where('batch_id', $batchId)->delete();
    }

    private function toRecord(ChunkyBatch $model): BatchRecord
    {
        return new BatchRecord(
            batchId: $model->batch_id,
            totalFiles: $model->total_files,
            completedFiles: $model->completed_files,
            failedFiles: $model->failed_files,
            status: $model->status,
            profile: $model->profile,
            metadata: $model->metadata ?? [],
            userId: $model->user_id,
            expiresAt: $model->expires_at?->toDateTimeImmutable(),
        );
    }
}
