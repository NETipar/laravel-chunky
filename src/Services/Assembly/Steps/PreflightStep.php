<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly\Steps;

use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Ports\ChunkStore;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use NETipar\Chunky\Services\Assembly\AssemblyStep;

final class PreflightStep implements AssemblyStep
{
    public function __construct(
        private readonly ChunkStore $chunks,
    ) {}

    public function execute(AssemblyState $state): void
    {
        for ($index = 0; $index < $state->record->totalChunks; $index++) {
            if (! $this->chunks->exists($state->record->uploadId, $index)) {
                throw new ChunkyException("Chunk {$index} for upload '{$state->record->uploadId}' could not be read.");
            }
        }
    }

    public function compensate(AssemblyState $state): void {}
}
