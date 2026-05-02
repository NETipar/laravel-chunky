<?php

declare(strict_types=1);

namespace NETipar\Chunky\Trackers;

use Illuminate\Support\Facades\DB;
use NETipar\Chunky\Contracts\UploadTracker;
use NETipar\Chunky\Data\UploadMetadata;
use NETipar\Chunky\Enums\UploadStatus;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Exceptions\UploadExpiredException;
use NETipar\Chunky\Models\ChunkedUpload;

class DatabaseTracker implements UploadTracker
{
    public function initiate(string $uploadId, UploadMetadata $metadata): void
    {
        ChunkedUpload::create([
            'upload_id' => $uploadId,
            'batch_id' => $metadata->batchId,
            'user_id' => $metadata->userId,
            'file_name' => $metadata->fileName,
            'file_size' => $metadata->fileSize,
            'mime_type' => $metadata->mimeType,
            'chunk_size' => $metadata->chunkSize,
            'total_chunks' => $metadata->totalChunks,
            'uploaded_chunks' => [],
            'disk' => $metadata->disk,
            'context' => $metadata->context,
            'metadata' => $metadata->metadata ?: null,
            'status' => UploadStatus::Pending,
            'expires_at' => now()->addMinutes(config('chunky.lifecycle.expiration_minutes', 360)),
        ]);
    }

    public function markChunkUploaded(string $uploadId, int $chunkIndex): UploadMetadata
    {
        return DB::transaction(function () use ($uploadId, $chunkIndex): UploadMetadata {
            $upload = ChunkedUpload::where('upload_id', $uploadId)
                ->lockForUpdate()
                ->first();

            if (! $upload) {
                throw new ChunkyException("Upload {$uploadId} not found.");
            }

            if ($upload->isExpired()) {
                $upload->update(['status' => UploadStatus::Expired]);

                throw UploadExpiredException::forUpload($uploadId);
            }

            // Reject late chunk writes against an upload that has already
            // settled into a terminal or in-progress-elsewhere state. Without
            // this guard a chunk POST that races a cancel/complete leaves
            // orphan files on disk and inconsistent tracker rows.
            if ($upload->status !== UploadStatus::Pending) {
                throw new ChunkyException(
                    "Upload {$uploadId} is no longer accepting chunks (status: {$upload->status->value}).",
                );
            }

            $upload->markChunkUploaded($chunkIndex);

            return $this->modelToMetadata($upload->refresh());
        });
    }

    private function modelToMetadata(ChunkedUpload $upload): UploadMetadata
    {
        return UploadMetadata::fromArray([
            'upload_id' => $upload->upload_id,
            'batch_id' => $upload->batch_id,
            'user_id' => $upload->user_id,
            'file_name' => $upload->file_name,
            'file_size' => $upload->file_size,
            'mime_type' => $upload->mime_type,
            'chunk_size' => $upload->chunk_size,
            'total_chunks' => $upload->total_chunks,
            'uploaded_chunks' => $upload->uploaded_chunks,
            'disk' => $upload->disk,
            'context' => $upload->context,
            'metadata' => $upload->metadata,
            'status' => $upload->status,
            'final_path' => $upload->final_path,
        ]);
    }

    /**
     * @return array<int, int>
     */
    public function getUploadedChunks(string $uploadId): array
    {
        $upload = $this->findOrFail($uploadId);

        return $upload->uploaded_chunks ?? [];
    }

    public function isComplete(string $uploadId): bool
    {
        $upload = $this->findOrFail($uploadId);

        return $upload->isComplete();
    }

    public function getMetadata(string $uploadId): ?UploadMetadata
    {
        $upload = ChunkedUpload::where('upload_id', $uploadId)->first();

        if (! $upload) {
            return null;
        }

        return $this->modelToMetadata($upload);
    }

    public function expire(string $uploadId): void
    {
        ChunkedUpload::where('upload_id', $uploadId)->update([
            'status' => UploadStatus::Expired,
        ]);
    }

    public function updateStatus(string $uploadId, UploadStatus $status, ?string $finalPath = null): void
    {
        $data = ['status' => $status];

        if ($finalPath) {
            $data['final_path'] = $finalPath;
        }

        if ($status === UploadStatus::Completed) {
            $data['completed_at'] = now();
        }

        ChunkedUpload::where('upload_id', $uploadId)->update($data);
    }

    public function claimForAssembly(string $uploadId): bool
    {
        $staleThreshold = now()->subMinutes(
            (int) config('chunky.lifecycle.assembly_stale_after_minutes', 10),
        );

        // Explicit allowlist of source statuses for the CAS, then the
        // per-status guard. Without the leading whereIn() a future enum
        // case (e.g. Stalled, Quarantined) would silently slip through
        // the orWhere chain — the test suite would not catch it because
        // the bare `where('status', Pending)` short-circuit would still
        // reject. Whitelisting the source statuses up-front makes the
        // intent declarative.
        //
        // The two transitions allowed:
        //  - Pending → Assembling: the normal first claim.
        //  - Assembling → Assembling (refreshed updated_at) when the row
        //    hasn't been touched in `assembly_stale_after_minutes`. This
        //    lets a retry recover an upload whose first worker died
        //    after flipping the status but before persisting Completed.
        $updated = ChunkedUpload::where('upload_id', $uploadId)
            ->whereIn('status', [UploadStatus::Pending, UploadStatus::Assembling])
            ->where(function ($query) use ($staleThreshold) {
                $query->where('status', UploadStatus::Pending)
                    ->orWhere(function ($query) use ($staleThreshold) {
                        $query->where('status', UploadStatus::Assembling)
                            ->where('updated_at', '<', $staleThreshold);
                    });
            })
            ->update(['status' => UploadStatus::Assembling, 'updated_at' => now()]);

        return $updated > 0;
    }

    /**
     * @return array<int, string>
     */
    public function expiredUploadIds(): array
    {
        $staleThreshold = now()->subMinutes(
            (int) config('chunky.lifecycle.assembly_stale_after_minutes', 10),
        );

        // Skip uploads that are still actively being assembled (Assembling
        // with a recent updated_at), but DO include stale Assembling rows
        // so a crash mid-assembly doesn't leak forever.
        return ChunkedUpload::query()
            ->where('expires_at', '<', now())
            ->where(function ($query) use ($staleThreshold) {
                $query->where('status', '!=', UploadStatus::Assembling)
                    ->orWhere(function ($query) use ($staleThreshold) {
                        $query->where('status', UploadStatus::Assembling)
                            ->where('updated_at', '<', $staleThreshold);
                    });
            })
            ->pluck('upload_id')
            ->all();
    }

    public function forget(string $uploadId): void
    {
        ChunkedUpload::where('upload_id', $uploadId)->delete();
    }

    private function findOrFail(string $uploadId): ChunkedUpload
    {
        $upload = ChunkedUpload::where('upload_id', $uploadId)->first();

        if (! $upload) {
            throw new ChunkyException("Upload {$uploadId} not found.");
        }

        if ($upload->isExpired()) {
            $this->expire($uploadId);
            throw UploadExpiredException::forUpload($uploadId);
        }

        return $upload;
    }
}
