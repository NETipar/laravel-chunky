<?php

declare(strict_types=1);

namespace NETipar\Chunky\Http\Controllers\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use NETipar\Chunky\Authorization\Authorizer;
use NETipar\Chunky\Domain\UploadRecord;
use NETipar\Chunky\Domain\UploadStatus;
use NETipar\Chunky\Exceptions\UploadNotFoundException;
use NETipar\Chunky\Services\UploadService;
use Throwable;

trait ResolvesOwnedUpload
{
    /**
     * A non-owner is answered identically to a missing upload (404) so upload
     * ids cannot be enumerated.
     */
    protected function ownedUploadOr404(
        UploadService $uploads,
        Authorizer $authorizer,
        ?Authenticatable $user,
        string $uploadId,
    ): UploadRecord {
        $upload = $uploads->find($uploadId);

        if ($upload === null || ! $authorizer->owns($user, $upload)) {
            throw UploadNotFoundException::forUpload($uploadId);
        }

        return $upload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusPayload(UploadRecord $upload, FilesystemFactory $filesystem): array
    {
        $data = [
            'upload_id' => $upload->uploadId,
            'status' => $upload->status->value,
            'progress' => $upload->progress(),
            'uploaded_chunks' => $upload->uploadedChunks,
            'total_chunks' => $upload->totalChunks,
            'file_name' => $upload->fileName,
            'file_size' => $upload->fileSize,
        ];

        if ($upload->status === UploadStatus::Completed) {
            $data['file'] = $this->completedFile($upload, $filesystem);
            $data['payload'] = $upload->resultPayload;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function completedFile(UploadRecord $upload, FilesystemFactory $filesystem): ?array
    {
        if ($upload->finalPath === null) {
            return null;
        }

        $file = ['path' => $upload->finalPath, 'size' => $upload->fileSize];

        try {
            $file['url'] = $filesystem->disk($upload->disk)->url($upload->finalPath);
        } catch (Throwable) {
            // Disk does not expose public URLs; omit.
        }

        return $file;
    }
}
