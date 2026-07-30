<?php

declare(strict_types=1);

namespace NETipar\Chunky\Services\Assembly\Steps;

use Illuminate\Contracts\Filesystem\Filesystem;
use NETipar\Chunky\Exceptions\ChunkIntegrityException;
use NETipar\Chunky\Exceptions\ChunkyException;
use NETipar\Chunky\Services\Assembly\AssemblyState;
use NETipar\Chunky\Services\Assembly\AssemblyStep;

/**
 * Verifies the assembled staging file matches the declared size — catches
 * truncated or over-long assemblies before they are published — and, when the
 * client reported a whole-file checksum, compares it against the SHA-256
 * computed during the merge.
 */
final class IntegrityStep implements AssemblyStep
{
    public function __construct(
        private readonly Filesystem $disk,
    ) {}

    public function execute(AssemblyState $state): void
    {
        if ($state->stagingPath === null) {
            throw new ChunkyException('No staging file to verify.');
        }

        $expected = $state->record->fileSize;

        if ($expected > 0) {
            $actual = $this->disk->size($state->stagingPath);

            if ($actual !== $expected) {
                throw new ChunkyException(
                    "Assembled size {$actual} does not match expected {$expected} for upload '{$state->record->uploadId}'.",
                );
            }
        }

        $reported = $state->record->fileChecksum;

        if ($reported === null || $state->computedChecksum === null) {
            return;
        }

        if (! hash_equals($state->computedChecksum, strtolower($reported))) {
            throw ChunkIntegrityException::fileChecksumMismatch($state->record->uploadId);
        }
    }

    public function compensate(AssemblyState $state): void {}
}
