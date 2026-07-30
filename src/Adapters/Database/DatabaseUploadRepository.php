<?php

declare(strict_types=1);

namespace NETipar\Chunky\Adapters\Database;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use NETipar\Chunky\Domain\ChunkProgress;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Models\ChunkedUpload;
use NETipar\Chunky\Ports\UploadRepository;

final class DatabaseUploadRepository implements UploadRepository
{
    public function create(UploadRecord $record): void
    {
        ChunkedUpload::query()->create([
            'upload_id' => $record->uploadId,
            'file_name' => $record->fileName,
            'file_size' => $record->fileSize,
            'mime_type' => $record->mimeType,
            'chunk_size' => $record->chunkSize,
            'total_chunks' => $record->totalChunks,
            'disk' => $record->disk,
            'profile' => $record->profile,
            'metadata' => $record->metadata,
            'uploaded_chunks' => $record->uploadedChunks,
            'status' => $record->status,
            'final_path' => $record->finalPath,
            'batch_id' => $record->batchId,
            'user_id' => $record->userId,
            'fingerprint' => $record->fingerprint,
            'file_checksum' => $record->fileChecksum,
            'expires_at' => $record->expiresAt,
            'claimed_at' => $record->claimedAt,
            'result_payload' => $record->resultPayload,
        ]);
    }

    public function find(string $uploadId): ?UploadRecord
    {
        $model = ChunkedUpload::query()->find($uploadId);

        return $model === null ? null : $this->toRecord($model);
    }

    public function findByFingerprint(string $fingerprint, ?string $userId): ?UploadRecord
    {
        $model = ChunkedUpload::query()
            ->where('fingerprint', $fingerprint)
            ->where('user_id', $userId)
            ->whereNotIn('status', $this->terminalStatuses())
            ->first();

        return $model === null ? null : $this->toRecord($model);
    }

    public function findByBatch(string $batchId): array
    {
        return array_values(
            ChunkedUpload::query()
                ->where('batch_id', $batchId)
                ->get()
                ->map(fn (ChunkedUpload $m): UploadRecord => $this->toRecord($m))
                ->all(),
        );
    }

    public function markChunk(string $uploadId, int $chunkIndex): ChunkProgress
    {
        return DB::transaction(function () use ($uploadId, $chunkIndex): ChunkProgress {
            $model = ChunkedUpload::query()
                ->where('upload_id', $uploadId)
                ->lockForUpdate()
                ->first();

            if ($model === null) {
                throw new ChunkyException("Upload '{$uploadId}' not found.");
            }

            $chunks = $model->uploaded_chunks ?? [];

            if (! in_array($chunkIndex, $chunks, true)) {
                $chunks[] = $chunkIndex;
                sort($chunks);
                $model->uploaded_chunks = $chunks;
                $model->save();
            }

            return new ChunkProgress(count($model->uploaded_chunks ?? []), $model->total_chunks);
        });
    }

    public function setFileChecksum(string $uploadId, string $checksum): void
    {
        ChunkedUpload::query()
            ->where('upload_id', $uploadId)
            ->whereNull('file_checksum')
            ->update(['file_checksum' => $checksum]);
    }

    public function transition(
        string $uploadId,
        UploadStatus $from,
        UploadStatus $to,
        array $attrs = [],
        array $guard = [],
    ): bool {
        $query = ChunkedUpload::query()
            ->where('upload_id', $uploadId)
            ->where('status', $from->value);

        if (isset($guard['claimed_before']) && $guard['claimed_before'] instanceof DateTimeImmutable) {
            $query->whereNotNull('claimed_at')
                ->where('claimed_at', '<', $guard['claimed_before']->format('Y-m-d H:i:s'));
        }

        $updates = ['status' => $to->value, 'updated_at' => now()];

        if (array_key_exists('final_path', $attrs)) {
            $updates['final_path'] = is_string($attrs['final_path']) ? $attrs['final_path'] : null;
        }

        if (array_key_exists('claimed_at', $attrs)) {
            $claimedAt = $attrs['claimed_at'];
            $updates['claimed_at'] = $claimedAt instanceof DateTimeImmutable
                ? $claimedAt->format('Y-m-d H:i:s')
                : null;
        }

        if (array_key_exists('result_payload', $attrs)) {
            $payload = $attrs['result_payload'];
            $updates['result_payload'] = is_array($payload) ? json_encode($payload) : null;
        }

        return $query->update($updates) > 0;
    }

    public function findExpired(DateTimeImmutable $before, int $limit): array
    {
        return array_values(
            ChunkedUpload::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $before->format('Y-m-d H:i:s'))
                ->whereNotIn('status', $this->terminalStatuses())
                ->limit($limit)
                ->get()
                ->map(fn (ChunkedUpload $m): UploadRecord => $this->toRecord($m))
                ->all(),
        );
    }

    public function delete(string $uploadId): void
    {
        ChunkedUpload::query()->where('upload_id', $uploadId)->delete();
    }

    private function toRecord(ChunkedUpload $model): UploadRecord
    {
        return new UploadRecord(
            uploadId: $model->upload_id,
            fileName: $model->file_name,
            fileSize: $model->file_size,
            mimeType: $model->mime_type,
            chunkSize: $model->chunk_size,
            totalChunks: $model->total_chunks,
            disk: $model->disk,
            profile: $model->profile,
            metadata: $model->metadata ?? [],
            uploadedChunks: array_values($model->uploaded_chunks ?? []),
            status: $model->status,
            finalPath: $model->final_path,
            batchId: $model->batch_id,
            userId: $model->user_id,
            fingerprint: $model->fingerprint,
            expiresAt: $model->expires_at?->toDateTimeImmutable(),
            claimedAt: $model->claimed_at?->toDateTimeImmutable(),
            resultPayload: $model->result_payload,
            fileChecksum: $model->file_checksum,
        );
    }

    /**
     * @return list<string>
     */
    private function terminalStatuses(): array
    {
        return [
            UploadStatus::Completed->value,
            UploadStatus::Failed->value,
            UploadStatus::Cancelled->value,
        ];
    }
}
