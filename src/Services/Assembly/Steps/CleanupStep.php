<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly\Steps;

use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use NETipar\Chunky\Services\Assembly\AssemblyStep;

/**
 * Removes the temporary chunks once the file is safely published. Runs last, so
 * a failure earlier in the pipeline leaves the chunks intact for retry/debug.
 */
final class CleanupStep implements AssemblyStep
{
    public function __construct(
        private readonly ChunkStore $chunks,
    ) {}

    public function execute(AssemblyState $state): void
    {
        $this->chunks->purge($state->record->uploadId);
    }

    public function compensate(AssemblyState $state): void {}
}
