<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly\Steps;

use Illuminate\Contracts\Filesystem\Filesystem;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use NETipar\Chunky\Services\Assembly\AssemblyStep;

/**
 * Publishes the staging file to its final path (computed and traversal-guarded
 * at initiate). Compensation deletes the published file so a later step's
 * failure leaves nothing behind.
 */
final class MoveStep implements AssemblyStep
{
    public function __construct(
        private readonly Filesystem $disk,
    ) {}

    public function execute(AssemblyState $state): void
    {
        $final = $state->record->finalPath;

        if ($final === null || $state->stagingPath === null) {
            throw new ChunkyException("Missing final or staging path for upload '{$state->record->uploadId}'.");
        }

        if (str_contains($final, '..')) {
            throw new ChunkyException("Refusing to write to an unsafe final path for upload '{$state->record->uploadId}'.");
        }

        if ($this->disk->exists($final)) {
            $this->disk->delete($final);
        }

        $this->disk->move($state->stagingPath, $final);
        $state->finalPath = $final;
        $state->stagingPath = null;
    }

    public function compensate(AssemblyState $state): void
    {
        if ($state->finalPath !== null && $this->disk->exists($state->finalPath)) {
            $this->disk->delete($state->finalPath);
        }
    }
}
