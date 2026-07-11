<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly\Steps;

use NETipar\Chunky\Profiles\CompletedUpload;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use NETipar\Chunky\Services\Assembly\AssemblyStep;

/**
 * Runs the profile's completed() hook after the file is in place. Its return
 * value becomes the response payload. If it throws, the pipeline compensates
 * the move (deletes the published file) and the upload is marked failed.
 */
final class ProfileStep implements AssemblyStep
{
    public function execute(AssemblyState $state): void
    {
        if ($state->profile === null) {
            return;
        }

        $record = $state->record;

        $state->payload = $state->profile->completed(new CompletedUpload(
            uploadId: $record->uploadId,
            disk: $state->disk,
            path: $state->finalPath ?? (string) $record->finalPath,
            fileName: $record->fileName,
            fileSize: $record->fileSize,
            mimeType: $record->mimeType,
            metadata: $record->metadata,
            userId: $record->userId,
            batchId: $record->batchId,
        ));
    }

    public function compensate(AssemblyState $state): void {}
}
